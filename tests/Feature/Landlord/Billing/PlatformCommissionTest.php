<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Jobs\ChargePlatformCommissions;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Models\PlatformCommission;
use App\Modules\Billing\Services\PlatformCommissionService;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    config(['app.payments_live_allowed' => true]);
    $this->seedPlans();
    $this->platformGateway('paystack', 'live');
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Landlord);
    app(PlatformSettingsService::class)->set('commission_enabled', true);
    app(PlatformSettingsService::class)->set('default_commission_rate', '2.5');

    $this->tenant = $this->createTenant('a');
    $this->subscription = $this->subscribe($this->tenant, 'standard', attributes: [
        'gateway' => 'paystack', 'gateway_mode' => 'live', 'authorization_reference' => 'AUTH_saved',
    ]);

    tenancy()->initialize($this->tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->shoe, null, '20', 'adjustment_in');
});

/**
 * A USD 80 order paid online in full, live or test.
 */
function commissionPaidOrder(bool $isTest = false): OrderPayment
{
    tenancy()->initialize(test()->tenant);
    $order = app(OrderService::class)->createOrder([
        'currency_code' => 'USD',
        'is_test' => $isTest,
        'guest_token' => 'guest-token-commission-'.Str::random(20),
        'customer_name' => 'Ada Obi',
        'customer_email' => 'ada@shop.test',
        'lines' => [['product' => test()->shoe, 'variant' => null, 'warehouse' => test()->main, 'quantity' => '2', 'unit_price' => '40', 'price_source' => 'base',
            'discount_amount' => '0', 'tax_rate_applied' => '0', 'tax_amount' => '0', 'line_total' => '80']],
        'totals' => ['subtotal' => '80', 'discount_amount' => '0', 'shipping_amount' => '0', 'tax_amount' => '0', 'total' => '80'],
        'shipping_address' => ['name' => 'Ada Obi', 'line1' => '1 Marina', 'country_id' => 1],
    ]);

    $payment = commissionPaymentRow($order, OrderPayment::PAYMENT, '80');
    app(OrderPaymentService::class)->completeGatewayPayment($payment, ['status' => 'successful', 'amount' => '80', 'currency_code' => 'USD', 'provider_reference' => 'PSK-'.Str::random(8)]);

    return $payment->refresh();
}

function commissionPaymentRow(Order $order, string $kind, string $amount, ?int $refundOf = null): OrderPayment
{
    $row = new OrderPayment;
    $row->forceFill([
        'order_id' => $order->id, 'kind' => $kind, 'payment_method' => 'gateway', 'provider' => 'paystack',
        'mode' => $order->is_test ? 'test' : 'live', 'status' => OrderPayment::PENDING, 'reference' => 'T-'.Str::upper(Str::random(10)),
        'amount_due' => $amount, 'amount_paid' => $amount, 'currency_code' => 'USD', 'refund_of_order_payment_id' => $refundOf,
    ])->save();

    return $row;
}

it('records commission on live online payments, reverses it pro rata on refunds and never on test orders', function (): void {
    $payment = commissionPaidOrder();

    expect($payment->meta['platform_commission'])->toBe(['rate' => '2.5000', 'amount' => '2.0000', 'currency_code' => 'USD'])
        ->and(PlatformCommission::query()->where('kind', 'payment')->value('amount'))->toBe('2.0000');

    // Half refunded: half the commission comes back.
    $refund = commissionPaymentRow($payment->order, OrderPayment::REFUND, '-40', $payment->id);
    app(OrderPaymentService::class)->completeRefund($refund, OrderPayment::SUCCESSFUL);
    expect(PlatformCommission::query()->where('kind', 'refund')->value('amount'))->toBe('-1.0000');

    // Test orders and repeated recording add nothing.
    commissionPaidOrder(isTest: true);
    tenancy()->initialize($this->tenant);
    app(PlatformCommissionService::class)->reconcile();
    expect(PlatformCommission::query()->count())->toBe(2);

    $auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
    $this->tenantJson('GET', '/api/admin/billing/commissions', [], $auth)->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('meta.outstanding.USD', '1.0000');
});

it('charges the net pending commission once a month without touching the subscription', function (): void {
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'success', 'id' => 991]])]);
    commissionPaidOrder();
    $renewsAt = $this->subscription->refresh()->renews_at?->toIso8601String();

    (new ChargePlatformCommissions)->handle(app(SubscriptionService::class));
    (new ChargePlatformCommissions)->handle(app(SubscriptionService::class));

    $charge = PaymentTransaction::query()->where('meta->purpose', 'commission')->sole();
    expect((string) $charge->amount)->toBe('2.0000')
        ->and($charge->status)->toBe(PaymentTransaction::SUCCESSFUL)
        ->and($charge->is_first_paid_charge)->toBeFalse()
        ->and(PlatformCommission::query()->sole()->status)->toBe(PlatformCommission::COLLECTED)
        ->and($this->subscription->refresh()->renews_at?->toIso8601String())->toBe($renewsAt);
    Http::assertSentCount(1);
    Notification::assertSentTo($this->tenant, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'subscription.commission_charged');
});

it('returns commission to pending when the charge fails, and lets an admin waive only pending rows', function (): void {
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient funds']])]);
    commissionPaidOrder();

    (new ChargePlatformCommissions)->handle(app(SubscriptionService::class));

    $row = PlatformCommission::query()->sole();
    expect($row->status)->toBe(PlatformCommission::PENDING)
        ->and($row->payment_transaction_id)->toBeNull()
        ->and($this->subscription->refresh()->status->value)->toBe('active');
    Notification::assertSentTo($this->tenant, TemplatedNotification::class, fn (TemplatedNotification $n): bool => $n->key === 'subscription.commission_charge_failed');

    $admin = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $waived = app(PlatformCommissionService::class)->waive($row, $admin->id, 'Goodwill');
    expect($waived->status)->toBe(PlatformCommission::WAIVED);
    expect(fn () => app(PlatformCommissionService::class)->waive($waived, $admin->id, 'Again'))->toThrow(ApiException::class);
});
