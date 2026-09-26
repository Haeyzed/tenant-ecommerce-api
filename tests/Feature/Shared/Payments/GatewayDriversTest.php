<?php

declare(strict_types=1);

use App\Shared\Exceptions\ApiException;
use App\Shared\Payments\DTOs\ChargeRequest;
use App\Shared\Payments\DTOs\WebhookEvent;
use App\Shared\Payments\GatewayCredentials;
use App\Shared\Payments\Gateways\FlutterwaveGateway;
use App\Shared\Payments\Gateways\PaystackGateway;
use App\Shared\Payments\Gateways\StripeGateway;
use App\Shared\Payments\PaymentGatewayException;
use App\Shared\Payments\PaymentGatewayFactory;
use App\Shared\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function chargeRequest(string $amount = '20.00', string $currency = 'USD'): ChargeRequest
{
    return new ChargeRequest($amount, $currency, 'SUB-REF-1', 'owner@example.test', 'Owner', 'https://app.test/callback', ['tenant_id' => 't1'], true);
}

it('converts money to and from minor units without floats', function (): void {
    expect(Money::toMinor('20.00', 'USD'))->toBe(2000)
        ->and(Money::toMinor('19.995', 'USD'))->toBe(2000)
        ->and(Money::toMinor('1500', 'JPY'))->toBe(1500)
        ->and(Money::fromMinor(2050, 'NGN'))->toBe('20.5000')
        ->and(Money::format('1250.5', 'USD'))->toBe('USD 1,250.50');
});

it('verifies Paystack signatures over the raw body', function (): void {
    $driver = new PaystackGateway(new GatewayCredentials('paystack', 'test', 'sk_test_x'));
    $raw = '{"event":"charge.success"}';

    expect($driver->verifyWebhookSignature($raw, ['x-paystack-signature' => hash_hmac('sha512', $raw, 'sk_test_x')]))->toBeTrue()
        ->and($driver->verifyWebhookSignature($raw.' ', ['x-paystack-signature' => hash_hmac('sha512', $raw, 'sk_test_x')]))->toBeFalse()
        ->and($driver->verifyWebhookSignature($raw, []))->toBeFalse();
});

it('parses Paystack charge, refund and dispute events', function (): void {
    $driver = new PaystackGateway(new GatewayCredentials('paystack', 'test', 'sk_test_x'));

    $charge = $driver->parseWebhookEvent(['event' => 'charge.success', 'data' => [
        'id' => 42, 'reference' => 'SUB-1', 'amount' => 2000, 'currency' => 'USD', 'fees' => 100, 'domain' => 'test',
        'authorization' => ['authorization_code' => 'AUTH_x', 'reusable' => true, 'signature' => 'SIG'],
    ]], 'raw-1');

    expect($charge->type)->toBe(WebhookEvent::CHARGE_SUCCEEDED)
        ->and($charge->amount)->toBe('20.0000')
        ->and($charge->fee)->toBe('1.0000')
        ->and($charge->authorizationToken)->toBe('AUTH_x')
        ->and($charge->livemode)->toBeFalse()
        ->and($charge->eventId)->toBe(hash('sha256', 'raw-1'));

    $dispute = $driver->parseWebhookEvent(['event' => 'charge.dispute.resolve', 'data' => [
        'id' => 7, 'resolution' => 'declined', 'transaction' => ['reference' => 'SUB-1', 'amount' => 2000, 'currency' => 'USD'],
    ]], 'raw-2');

    expect($dispute->type)->toBe(WebhookEvent::DISPUTE_WON)->and($dispute->reference)->toBe('SUB-1');
});

it('charges a Paystack authorization with our reference', function (): void {
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'success', 'id' => 99]])]);

    $result = (new PaystackGateway(new GatewayCredentials('paystack', 'test', 'sk_test_x')))->chargeAuthorization('AUTH_x', chargeRequest());

    expect($result['status'])->toBe('successful')->and($result['provider_reference'])->toBe('99');

    Http::assertSent(fn (Request $request): bool => $request['reference'] === 'SUB-REF-1'
        && $request['amount'] === 2000
        && $request->hasHeader('Authorization', 'Bearer sk_test_x'));
});

it('reports a timeout as pending, never as failed', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => (new PaystackGateway(new GatewayCredentials('paystack', 'test', 'sk_test_x')))->initiateCharge(chargeRequest()))
        ->toThrow(fn (PaymentGatewayException $e) => expect($e->pending)->toBeTrue());
});

it('detects the mode of the keys', function (): void {
    Http::fake(['*' => Http::response(['status' => true, 'data' => []])]);

    expect((new PaystackGateway(new GatewayCredentials('paystack', 'test', 'sk_live_abc')))->validateCredentials()['detected_mode'])->toBe('live')
        ->and((new FlutterwaveGateway(new GatewayCredentials('flutterwave', 'test', 'FLWSECK_TEST-abc')))->validateCredentials()['detected_mode'])->toBe('test');
});

it('verifies Flutterwave webhooks with the secret hash', function (): void {
    $driver = new FlutterwaveGateway(new GatewayCredentials('flutterwave', 'test', 'FLWSECK_TEST-x', null, 'my-hash'));

    expect($driver->verifyWebhookSignature('{}', ['verif-hash' => 'my-hash']))->toBeTrue()
        ->and($driver->verifyWebhookSignature('{}', ['verif-hash' => 'other']))->toBeFalse();

    $event = $driver->parseWebhookEvent(['event' => 'charge.completed', 'data' => ['id' => 5, 'tx_ref' => 'SUB-1', 'status' => 'successful', 'amount' => 20, 'currency' => 'NGN']], '{}');

    expect($event->type)->toBe(WebhookEvent::CHARGE_SUCCEEDED)->and($event->amount)->toBe('20.0000');
});

it('verifies Stripe signatures within the replay window only', function (): void {
    $driver = new StripeGateway(new GatewayCredentials('stripe', 'test', 'sk_test_x', null, 'whsec_x'));
    $raw = '{"id":"evt_1"}';
    $sign = fn (int $t): string => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$raw, 'whsec_x');

    expect($driver->verifyWebhookSignature($raw, ['stripe-signature' => $sign(now()->getTimestamp())]))->toBeTrue()
        ->and($driver->verifyWebhookSignature($raw, ['stripe-signature' => $sign(now()->subMinutes(6)->getTimestamp())]))->toBeFalse();
});

it('sends our reference as the Stripe idempotency key on renewals', function (): void {
    Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_1', 'status' => 'succeeded'])]);

    $result = (new StripeGateway(new GatewayCredentials('stripe', 'test', 'sk_test_x')))->chargeAuthorization('cus_1|pm_1', chargeRequest());

    expect($result['status'])->toBe('successful');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'SUB-REF-1') && $request['customer'] === 'cus_1');
});

it('refuses to build a live driver while live payments are disabled', function (): void {
    config(['app.payments_live_allowed' => false]);
    $this->platformGateway('paystack', 'live', attributes: ['secret_key' => 'sk_live_x']);

    expect(fn () => app(PaymentGatewayFactory::class)->forPlatform('paystack', 'live'))
        ->toThrow(ApiException::class);
});
