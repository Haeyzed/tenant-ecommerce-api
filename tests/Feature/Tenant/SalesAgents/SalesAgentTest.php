<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Models\Order;
use App\Modules\SalesAgents\Models\SalesAgentCommission;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    app(TenantSettingsService::class)->set('payment_mode', 'live');
    app(TenantSettingsService::class)->set('default_sales_agent_commission_rate', '5');
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$owner->createToken('t', ['staff'])->plainTextToken];

    $this->guide = Product::query()->create(['name' => 'Guide', 'price' => '5000', 'product_type' => 'digital', 'is_active' => true]);

    $this->tenantJson('POST', '/api/admin/modules/sales_agents/enable', [], $this->staff)->assertOk();
    $this->bola = $this->tenantJson('POST', '/api/admin/sales-agents', ['name' => 'Bola Ade', 'phone' => '+2348000000002', 'email' => 'Bola@Agents.test'], $this->staff)
        ->assertCreated()->json('data');
});

/**
 * A guest buys two guides (10,000) with the given checkout extras.
 */
function agentCheckout(array $extra = []): TestResponse
{
    $token = test()->tenantJson('POST', '/api/cart/items', ['product_id' => test()->guide->id, 'quantity' => 2])->assertCreated()->json('data.guest_token');
    $quote = test()->tenantJson('GET', '/api/cart', [], ['X-Guest-Token' => $token])->assertOk()->json('data.quote');

    return test()->tenantJson('POST', '/api/orders', ['quote_hash' => $quote['quote_hash'], 'guest_email' => 'ada@shop.test', ...$extra],
        ['X-Guest-Token' => $token, 'Idempotency-Key' => Str::uuid()->toString()]);
}

function agentPayCash(int $orderId, string $amount): void
{
    test()->tenantJson('POST', "/api/admin/orders/{$orderId}/payments", ['payment_method' => 'cash', 'amount' => $amount], [...test()->staff, 'Idempotency-Key' => Str::uuid()->toString()])->assertCreated();
}

it('credits a checkout referral with a commission that staff approve, then mark paid', function (): void {
    expect($this->bola['agent_code'])->toMatch('/^BOLA[A-Z0-9]{4}$/')
        ->and($this->bola)->toMatchArray(['email' => 'bola@agents.test', 'commission_rate' => null, 'effective_commission_rate' => '5.0000', 'status' => 'active']);
    $this->tenantJson('POST', '/api/admin/sales-agents', ['name' => 'Copy', 'phone' => '1', 'agent_code' => strtolower($this->bola['agent_code'])], $this->staff)
        ->assertStatus(422)->assertJsonValidationErrors('agent_code');

    agentCheckout(['sales_agent_code' => 'NOPE123'])->assertStatus(422)->assertJsonPath('meta.error_code', 'sales_agent_invalid');
    $order = agentCheckout(['sales_agent_code' => strtolower($this->bola['agent_code'])])->assertCreated()->json('data');
    $this->tenantJson('GET', "/api/admin/orders/{$order['id']}", [], $this->staff)->assertOk()->assertJsonPath('data.sales_agent_id', $this->bola['id']);

    // Nothing is earned until the sale is confirmed.
    tenancy()->initialize($this->tenant);
    expect(SalesAgentCommission::query()->count())->toBe(0);

    agentPayCash($order['id'], '10000');
    $commissions = $this->tenantJson('GET', "/api/admin/sales-agents/{$this->bola['id']}/commissions", [], $this->staff)->assertOk()->json('data');
    expect($commissions)->toHaveCount(1)
        ->and($commissions[0])->toMatchArray(['gross_amount' => '10000.0000', 'commission_rate_applied' => '5.0000', 'commission_amount' => '500.0000', 'status' => 'pending'])
        ->and($commissions[0]['order']['order_number'])->toBe($order['order_number']);

    $id = $commissions[0]['id'];
    $this->tenantJson('PATCH', "/api/admin/sales-agent-commissions/{$id}/mark-paid", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'invalid_transition');
    $this->tenantJson('PATCH', "/api/admin/sales-agent-commissions/{$id}/approve", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'approved');
    $this->tenantJson('GET', "/api/admin/sales-agents/{$this->bola['id']}/balance", [], $this->staff)->assertOk()
        ->assertJsonPath('data.outstanding', '500.0000')->assertJsonPath('data.pending', '0.0000')->assertJsonPath('data.currency_code', 'NGN');
    $this->tenantJson('PATCH', "/api/admin/sales-agent-commissions/{$id}/mark-paid", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'paid');
    $this->tenantJson('GET', "/api/admin/sales-agents/{$this->bola['id']}/balance", [], $this->staff)->assertOk()
        ->assertJsonPath('data.outstanding', '0.0000')->assertJsonPath('data.paid', '500.0000');

    // The section shows the paid commission and the agent at the top.
    $section = $this->tenantJson('GET', '/api/admin/dashboard/sales_agents?range=today&compare=none', [], $this->staff)->assertOk()->json('data');
    expect(collect($section['kpis'])->keyBy('key')['commissions_paid']['value'])->toBe('500.0000')
        ->and($section['tables'][0]['rows'][0])->toMatchArray(['name' => 'Bola Ade', 'orders' => 1, 'commission' => '500.0000']);
});

it('uses the agent rate, never pays on test orders and fixes attribution at confirmation', function (): void {
    $this->tenantJson('PATCH', "/api/admin/sales-agents/{$this->bola['id']}", ['commission_rate' => '7.5'], $this->staff)->assertOk()
        ->assertJsonPath('data.effective_commission_rate', '7.5000');

    // Staff attribute an unpaid order, then it is confirmed.
    $order = agentCheckout()->assertCreated()->json('data');
    $this->tenantJson('PATCH', "/api/admin/orders/{$order['id']}/sales-agent", ['sales_agent_id' => $this->bola['id']], $this->staff)->assertOk()
        ->assertJsonPath('data.sales_agent_id', $this->bola['id']);
    agentPayCash($order['id'], '10000');
    $this->tenantJson('PATCH', "/api/admin/orders/{$order['id']}/sales-agent", ['sales_agent_id' => null], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'order_confirmed');

    tenancy()->initialize($this->tenant);
    expect((string) SalesAgentCommission::query()->where('order_id', $order['id'])->value('commission_amount'))->toBe('750.0000');

    // A rehearsal earns nothing.
    app(TenantSettingsService::class)->set('payment_mode', 'test');
    $test = agentCheckout(['sales_agent_code' => $this->bola['agent_code']])->assertCreated()->json('data');
    agentPayCash($test['id'], '10000');

    tenancy()->initialize($this->tenant);
    expect(Order::query()->findOrFail($test['id'])->confirmed_at)->not->toBeNull()
        ->and(SalesAgentCommission::query()->where('order_id', $test['id'])->exists())->toBeFalse();

    // An inactive agent credits nothing new.
    $this->tenantJson('DELETE', "/api/admin/sales-agents/{$this->bola['id']}", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'inactive');
    agentCheckout(['sales_agent_code' => $this->bola['agent_code']])->assertStatus(422)->assertJsonPath('meta.error_code', 'sales_agent_invalid');
    $this->tenantJson('GET', '/api/admin/sales-agents?status=active', [], $this->staff)->assertOk()->assertJsonCount(0, 'data');
});

it('credits the agent a POS sale names', function (): void {
    tenancy()->initialize($this->tenant);
    app(WarehouseService::class)->ensureDefault();
    $shop = Warehouse::query()->firstOrFail();
    $shoe = Product::query()->create(['name' => 'Runner', 'price' => '40000', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($shop, $shoe, null, '3', 'adjustment_in');

    $this->tenantJson('POST', '/api/admin/modules/pos/enable', [], $this->staff)->assertOk();
    $this->tenantJson('PUT', '/api/admin/pos/settings', ['cash_register_enabled' => false], $this->staff)->assertOk();
    $register = $this->tenantJson('POST', '/api/admin/pos/registers', ['warehouse_id' => $shop->id, 'name' => 'Front'], $this->staff)->assertCreated()->json('data');
    $sale = fn (?int $agentId) => $this->tenantJson('POST', '/api/admin/pos/sales', ['register_id' => $register['id'], 'idempotency_key' => Str::uuid()->toString(),
        'lines' => [['product_id' => $shoe->id, 'quantity' => 1]], 'payments' => [['method' => 'cash', 'amount' => '40000']], 'sales_agent_id' => $agentId], $this->staff);

    $sale(999999)->assertStatus(422)->assertJsonPath('meta.error_code', 'sales_agent_invalid');
    $order = $sale($this->bola['id'])->assertCreated()->json('data.sale');

    tenancy()->initialize($this->tenant);
    $commission = SalesAgentCommission::query()->where('order_id', $order['id'])->firstOrFail();
    expect($commission->sales_agent_id)->toBe($this->bola['id'])
        ->and((string) $commission->gross_amount)->toBe('40000.0000')
        ->and((string) $commission->commission_amount)->toBe('2000.0000');
});
