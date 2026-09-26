<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Billing\Models\PlatformPaymentGateway;
use Illuminate\Testing\TestResponse;

trait InteractsWithBilling
{
    protected const string PAYSTACK_TEST_SECRET = 'sk_test_0123456789abcdef';

    /**
     * An enabled, verified platform gateway row.
     *
     * @param  list<string>  $currencies
     */
    protected function platformGateway(string $provider = 'paystack', string $mode = 'test', array $currencies = ['USD', 'NGN'], array $attributes = []): PlatformPaymentGateway
    {
        /** @var PlatformPaymentGateway $row */
        $row = PlatformPaymentGateway::query()->create(array_merge([
            'provider' => $provider,
            'mode' => $mode,
            'public_key' => 'pk_'.$mode.'_public',
            'secret_key' => $provider === 'paystack' ? self::PAYSTACK_TEST_SECRET : ($provider === 'stripe' ? 'sk_test_stripe' : 'FLWSECK_TEST-secret'),
            'webhook_secret' => $provider === 'paystack' ? null : 'whsec_'.$provider,
            'is_enabled' => true,
            'is_default' => true,
            'supported_currencies' => $currencies,
            'credentials_verified_at' => now(),
        ], $attributes));

        return $row;
    }

    /**
     * Posts a Paystack webhook signed with the test secret.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function paystackWebhook(array $payload, string $mode = 'test', ?string $secret = null): TestResponse
    {
        $raw = (string) json_encode($payload);

        return $this->call('POST', 'http://'.$this->landlordHost().'/api/webhooks/paystack/'.$mode, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, $secret ?? self::PAYSTACK_TEST_SECRET),
        ], $raw);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function paystackChargeSuccess(string $reference, int $amountMinor, string $currency = 'USD', array $overrides = []): array
    {
        return [
            'event' => 'charge.success',
            'data' => array_merge([
                'id' => random_int(1_000_000, 9_999_999),
                'domain' => 'test',
                'status' => 'success',
                'reference' => $reference,
                'amount' => $amountMinor,
                'currency' => $currency,
                'fees' => 150,
                'paid_at' => now()->toIso8601String(),
                'authorization' => ['authorization_code' => 'AUTH_test123', 'reusable' => true, 'signature' => 'SIG_abc'],
            ], $overrides),
        ];
    }
}
