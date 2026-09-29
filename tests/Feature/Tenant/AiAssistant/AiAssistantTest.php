<?php

declare(strict_types=1);

use App\Modules\AiAssistant\Models\AiAssistantIntent;
use App\Modules\AiAssistant\Services\AiAssistantService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\OrderPaymentLedgerService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];
    // May ask and see orders, but not stock.
    $seller = User::query()->create(['name' => 'Floor', 'email' => 'floor@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $seller->givePermissionTo(['ai-assistant.ask', 'orders.view']);
    $this->sellerAuth = ['Authorization' => 'Bearer '.$seller->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $main = Warehouse::query()->firstOrFail();
    $shoe = Product::query()->create(['name' => 'Runner', 'sku' => 'RUN-1', 'price' => '40', 'cost_price' => '25', 'is_active' => true]);
    $mug = Product::query()->create(['name' => 'Mug', 'sku' => 'MUG-1', 'price' => '10', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($main, $shoe, null, '20', 'adjustment_in');
    app(InventoryService::class)->adjustStock($main, $mug, null, '2', 'adjustment_in');

    // One paid order (2 × 40, less 10) and one waiting for payment.
    foreach ([[$shoe, '2', '40', '10', true], [$mug, '1', '10', '0', false]] as [$product, $qty, $price, $discount, $pay]) {
        $gross = bcmul($qty, $price, 4);
        $total = bcsub($gross, $discount, 4);
        $order = app(OrderService::class)->createOrder([
            'currency_code' => 'NGN', 'is_test' => false, 'guest_token' => 'guest-token-assistant-0123456789abcdef', 'customer_name' => 'Ada', 'customer_email' => 'ada@shop.test',
            'lines' => [['product' => $product, 'variant' => null, 'warehouse' => $main, 'quantity' => $qty, 'unit_price' => $price, 'price_source' => 'base',
                'discount_amount' => $discount, 'line_total' => $total]],
            'totals' => ['subtotal' => $gross, 'discount_amount' => $discount, 'total' => $total],
            'shipping_address' => ['name' => 'Ada', 'line1' => '1 Marina', 'country_id' => 1],
        ]);

        if ($pay) {
            app(OrderPaymentLedgerService::class)->recordPayment($order, ['payment_method' => 'cash', 'amount' => $total], $this->owner);
        }
    }

    $this->tenantJson('POST', '/api/admin/modules/ai_assistant/enable', [], $this->staff)->assertOk();
});

it('answers catalogue questions from the same figures as the dashboard, and never guesses', function (): void {
    $this->tenantJson('GET', '/api/admin/ai-assistant/intents', [], $this->staff)->assertOk()->assertJsonCount(9, 'data');

    // Different phrasings of one question.
    foreach (["What were today's sales?", 'how much did we sell today', 'Sales today please'] as $question) {
        $this->tenantJson('POST', '/api/admin/ai-assistant/ask', ['question' => $question], $this->staff)->assertOk()
            ->assertJsonPath('data.matched', true)->assertJsonPath('data.intent_key', 'today_sales')
            ->assertJsonPath('data.data.net_sales', '70.0000')->assertJsonPath('data.data.orders', 1);
    }
    $dashboard = collect($this->tenantJson('GET', '/api/admin/dashboard/sales?range=today&compare=none', [], $this->staff)->json('data.kpis'))->pluck('value', 'key');
    expect($dashboard['net_sales'])->toBe('70.0000');

    $this->tenantJson('POST', '/api/admin/ai-assistant/ask', ['question' => 'Which orders are pending?'], $this->staff)->assertOk()
        ->assertJsonPath('data.intent_key', 'pending_orders')->assertJsonPath('data.data.count', 2)->assertJsonPath('data.data.by_status.pending', 2);
    $this->tenantJson('POST', '/api/admin/ai-assistant/ask', ['question' => 'what is running low?'], $this->staff)->assertOk()
        ->assertJsonPath('data.intent_key', 'low_stock')->assertJsonPath('data.data.items.0.sku', 'MUG-1');
    $this->tenantJson('POST', '/api/admin/ai-assistant/ask', ['question' => 'show me the best sellers'], $this->staff)->assertOk()
        ->assertJsonPath('data.intent_key', 'top_selling_products')->assertJsonPath('data.data.products.0.name', 'Runner');
    $snapshot = $this->tenantJson('POST', '/api/admin/ai-assistant/ask', ['question' => "Give me today's summary"], $this->staff)->assertOk()
        ->assertJsonPath('data.intent_key', 'daily_snapshot')->json('data');
    // Expenses are enabled (Basic and above); purchasing is not.
    expect($snapshot['data']['purchases'])->toBeNull()->and($snapshot['data']['expenses']['total'])->toBe('0.0000');

    // No match: a clarifying message. Purchasing is off, so its intents are not candidates.
    foreach (["What's the weather like?", 'purchases today'] as $question) {
        $this->tenantJson('POST', '/api/admin/ai-assistant/ask', ['question' => $question], $this->staff)->assertOk()
            ->assertJsonPath('data.matched', false)->assertJsonPath('data.data', null)
            ->assertJsonPath('data.answer', 'I can help with sales, purchases, expenses, stock and order questions. Try asking about one of those.');
    }

    $this->tenantJson('GET', '/api/admin/ai-assistant/query-logs?matched=0', [], $this->staff)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.question', 'purchases today');
    $this->tenantJson('POST', '/api/admin/ai-assistant/ask', ['question' => ''], $this->staff)->assertStatus(422);
});

it('answers with the asker\'s permissions and only while an intent is on', function (): void {
    // The floor seller sees orders, not stock.
    $this->tenantJson('POST', '/api/admin/ai-assistant/ask', ['question' => 'pending orders'], $this->sellerAuth)->assertOk()->assertJsonPath('data.data.count', 2);
    $this->tenantJson('POST', '/api/admin/ai-assistant/ask', ['question' => 'low stock'], $this->sellerAuth)->assertOk()
        ->assertJsonPath('data.matched', true)->assertJsonPath('data.data', null)->assertJsonPath('data.answer', fn (string $a): bool => str_contains($a, 'inventory.view'));
    $this->tenantJson('GET', '/api/admin/ai-assistant/query-logs', [], $this->sellerAuth)->assertForbidden();

    tenancy()->initialize($this->tenant);
    $lowStock = AiAssistantIntent::query()->where('intent_key', 'low_stock')->firstOrFail();
    $this->tenantJson('PATCH', "/api/admin/ai-assistant/intents/{$lowStock->id}/toggle", ['is_active' => false], $this->staff)->assertOk()->assertJsonPath('data.is_active', false);
    $this->tenantJson('POST', '/api/admin/ai-assistant/ask', ['question' => 'low stock'], $this->staff)->assertOk()->assertJsonPath('data.matched', false);

    // The defaults sync refreshes phrases but keeps an admin's choice.
    tenancy()->initialize($this->tenant);
    app(AiAssistantService::class)->seedIntents();
    expect(AiAssistantIntent::query()->where('intent_key', 'low_stock')->value('is_active'))->toBeFalse();

    // Switched off: no questions at all.
    $this->tenantJson('POST', '/api/admin/modules/ai_assistant/disable', [], $this->staff)->assertOk();
    $this->tenantJson('POST', '/api/admin/ai-assistant/ask', ['question' => 'sales today'], $this->staff)->assertForbidden();
});
