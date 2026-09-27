<?php

declare(strict_types=1);

use App\Modules\Accounting\Services\ChartOfAccountsService;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'premium');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    app(ChartOfAccountsService::class)->seedDefaults();
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->auth = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'sku' => 'RUN-1', 'price' => '40000', 'cost_price' => '20000', 'is_active' => true]);
    $this->bag = Product::query()->create(['name' => 'Tote', 'sku' => 'TOTE-1', 'price' => '9000', 'is_active' => true]);

    $this->tenantJson('POST', '/api/admin/modules/purchasing/enable', [], $this->auth)->assertOk();
});

function onHand(Product $product): string
{
    tenancy()->initialize(test()->tenant);

    return (string) (Inventory::query()->where('product_id', $product->id)->where('warehouse_id', test()->main->id)->value('quantity') ?? '0.000');
}

/**
 * Σ debits and credits per system account of the journal entries of a
 * posting key prefix.
 *
 * @return array<string, string>
 */
function ledger(string $keyPrefix): array
{
    tenancy()->initialize(test()->tenant);

    return DB::connection('tenant')->table('journal_entry_lines as l')
        ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
        ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('e.posting_key', 'like', $keyPrefix.'%')
        ->groupBy('a.system_key', 'l.type')
        ->selectRaw("CONCAT(a.system_key, ':', l.type) as k, SUM(l.amount) as amount")
        ->pluck('amount', 'k')->map(fn ($a): string => (string) $a)->all();
}

function poFor(int $supplierId, array $items, array $extra = []): array
{
    return test()->tenantJson('POST', '/api/admin/purchase-orders', ['supplier_id' => $supplierId, 'warehouse_id' => test()->main->id, 'items' => $items, ...$extra], test()->auth)
        ->assertCreated()->json('data');
}

it('buys stock: supplier, draft order with supplier costs, submit, partial and full receipt with accounting', function (): void {
    $this->tenantJson('POST', '/api/admin/modules/accounting/enable', [], $this->auth)->assertOk();
    $year = now()->year;
    $this->tenantJson('POST', '/api/admin/accounting/fiscal-years', ['name' => "FY{$year}", 'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31"], $this->auth)->assertCreated();

    $supplier = $this->tenantJson('POST', '/api/admin/suppliers', ['name' => 'Lagos Leather', 'email' => 'sales@leather.test', 'payment_terms' => 'Net 30'], $this->auth)
        ->assertCreated()->json('data');
    $this->tenantJson('POST', "/api/admin/suppliers/{$supplier['id']}/products", ['product_id' => $this->shoe->id, 'supplier_sku' => 'LL-RUN', 'cost_price' => '18000'], $this->auth)
        ->assertCreated()->assertJsonPath('data.cost_price', '18000.0000');
    $this->tenantJson('GET', "/api/admin/suppliers/by-product/{$this->shoe->id}", [], $this->auth)->assertOk()->assertJsonPath('data.0.supplier.name', 'Lagos Leather');

    // The shoe takes the supplier's cost; the bag has none anywhere, so it needs one.
    $this->tenantJson('POST', '/api/admin/purchase-orders', ['supplier_id' => $supplier['id'], 'warehouse_id' => $this->main->id,
        'items' => [['product_id' => $this->bag->id, 'quantity' => 5]]], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('items.0.unit_cost');

    $po = poFor($supplier['id'], [['product_id' => $this->shoe->id, 'quantity' => 10], ['product_id' => $this->bag->id, 'quantity' => 5, 'unit_cost' => '4000']]);
    expect($po['po_number'])->toBe('PO-000001')
        ->and($po['status'])->toBe('draft')
        ->and($po['currency_code'])->toBe('NGN')
        ->and($po['total'])->toBe('200000.0000')
        ->and(collect($po['items'])->pluck('unit_cost')->all())->toBe(['18000.0000', '4000.0000']);

    // Nothing is received from a draft.
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$po['id']}/receive", ['items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'quantity' => 1]]], $this->auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'invalid_transition');

    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$po['id']}/submit", [], $this->auth)->assertOk()
        ->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.exchange_rate_used', '1.000000000000');

    [$shoeLine, $bagLine] = [$po['items'][0]['id'], $po['items'][1]['id']];
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$po['id']}/receive", ['items' => [['purchase_order_item_id' => $shoeLine, 'quantity' => 11]]], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$po['id']}/receive", ['items' => [['purchase_order_item_id' => $shoeLine, 'quantity' => 4]]], $this->auth)
        ->assertOk()->assertJsonPath('data.status', 'partially_received')->assertJsonPath('data.items.0.quantity_received', '4.000');
    expect(onHand($this->shoe))->toBe('4.000');

    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$po['id']}/receive", ['items' => [['purchase_order_item_id' => $shoeLine, 'quantity' => 6], ['purchase_order_item_id' => $bagLine, 'quantity' => 5]]], $this->auth)
        ->assertOk()->assertJsonPath('data.status', 'received');
    expect(onHand($this->shoe))->toBe('10.000')->and(onHand($this->bag))->toBe('5.000');

    Notification::assertSentTo($this->owner, TemplatedNotification::class, fn ($n): bool => $n->key === 'purchase_order.received');

    // Two receipts: 72,000 then 128,000, each Dr Inventory, Cr Payables.
    expect(ledger('po_receipt:'.$po['id']))->toEqualCanonicalizing(['inventory_asset:debit' => '200000.0000', 'accounts_payable:credit' => '200000.0000']);

    // A received order can be neither cancelled nor edited.
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$po['id']}/cancel", [], $this->auth)->assertStatus(422);
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$po['id']}", ['notes' => 'x'], $this->auth)->assertStatus(422);

    // Duplicate: a new draft with the same lines, nothing received.
    $copy = $this->tenantJson('POST', "/api/admin/purchase-orders/{$po['id']}/duplicate", [], $this->auth)->assertCreated()->json('data');
    expect($copy['po_number'])->toBe('PO-000002')->and($copy['status'])->toBe('draft')->and($copy['items'][0]['quantity_received'])->toBe('0.000');

    // The product picker shows on-hand stock.
    $this->tenantJson('GET', '/api/admin/purchase-orders/product-lookup?q=Run', [], $this->auth)->assertOk()
        ->assertJsonPath('data.0.sku', 'RUN-1')->assertJsonPath('data.0.on_hand', '10.000');
});

it('pays suppliers, keeps order and supplier balances, and returns stock with a refund', function (): void {
    $this->tenantJson('POST', '/api/admin/modules/accounting/enable', [], $this->auth)->assertOk();
    $year = now()->year;
    $this->tenantJson('POST', '/api/admin/accounting/fiscal-years', ['name' => "FY{$year}", 'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31"], $this->auth)->assertCreated();

    $supplier = $this->tenantJson('POST', '/api/admin/suppliers', ['name' => 'Abuja Bags'], $this->auth)->assertCreated()->json('data');
    $po = poFor($supplier['id'], [['product_id' => $this->bag->id, 'quantity' => 10, 'unit_cost' => '5000']]);
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$po['id']}/submit", [], $this->auth)->assertOk();
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$po['id']}/receive", ['items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'quantity' => 10]]], $this->auth)->assertOk();

    // A payment against the order and a bulk one on account.
    $payment = $this->tenantJson('POST', "/api/admin/suppliers/{$supplier['id']}/payments", ['purchase_order_id' => $po['id'], 'amount_paid' => '30000', 'payment_method' => 'bank_transfer', 'reference' => 'TRF-1'], $this->auth)
        ->assertCreated()->assertJsonPath('data.amount_due', '50000.0000')->assertJsonPath('data.currency_code', 'NGN')->json('data');
    $this->tenantJson('POST', "/api/admin/suppliers/{$supplier['id']}/payments", ['amount_paid' => '5000', 'payment_method' => 'cash'], $this->auth)->assertCreated();

    $this->tenantJson('GET', "/api/admin/purchase-orders/{$po['id']}/balance", [], $this->auth)->assertOk()->assertJsonPath('data.balance', '20000.0000');
    $this->tenantJson('GET', "/api/admin/suppliers/{$supplier['id']}/balance", [], $this->auth)->assertOk()->assertJsonPath('data.balance', '15000.0000');
    $this->tenantJson('GET', '/api/admin/supplier-payments/outstanding', [], $this->auth)->assertOk()
        ->assertJsonPath('data.0.name', 'Abuja Bags')->assertJsonPath('data.0.balance', '15000.0000');

    // Editing the amount reverses and re-posts; the ledger nets to the new amount.
    $this->tenantJson('PATCH', "/api/admin/supplier-payments/{$payment['id']}", ['amount_paid' => '35000'], $this->auth)->assertOk();
    $paid = ledger('supplier_payment:'.$payment['id']);
    $reversed = ledger('supplier_payment_reversal:'.$payment['id']);
    expect(bcsub($paid['accounts_payable:debit'], $reversed['accounts_payable:credit'] ?? '0', 4))->toBe('35000.0000');

    // Return 2 damaged bags: stock leaves on approval, payables fall.
    tenancy()->initialize($this->tenant);
    $reasons = $this->tenantJson('GET', '/api/admin/purchase-return-reasons', [], $this->auth)->assertOk()->json('data');
    expect(count($reasons))->toBe(5); // seeded when purchasing was first enabled

    $this->tenantJson('POST', '/api/admin/purchase-returns', ['purchase_order_id' => $po['id'], 'purchase_return_reason_id' => $reasons[0]['id'],
        'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'quantity' => 11]]], $this->auth)
        ->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
    $return = $this->tenantJson('POST', '/api/admin/purchase-returns', ['purchase_order_id' => $po['id'], 'purchase_return_reason_id' => $reasons[0]['id'],
        'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'quantity' => 2]], 'note' => 'Torn straps'], $this->auth)
        ->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.value', '10000.0000')->json('data');

    $this->tenantJson('PATCH', "/api/admin/purchase-returns/{$return['id']}/approve", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'approved');
    expect(onHand($this->bag))->toBe('8.000')
        ->and(ledger('purchase_return:'.$return['id']))->toEqualCanonicalizing(['accounts_payable:debit' => '10000.0000', 'inventory_asset:credit' => '10000.0000']);

    // Order balance: 50,000 − 35,000 paid − 10,000 returned.
    $this->tenantJson('GET', "/api/admin/purchase-orders/{$po['id']}/balance", [], $this->auth)->assertOk()->assertJsonPath('data.balance', '5000.0000');

    // The supplier refunds 10,000: a negative payment on the order, locked to the return.
    $this->tenantJson('PATCH', "/api/admin/purchase-returns/{$return['id']}/ship-back", [], $this->auth)->assertOk();
    $this->tenantJson('POST', "/api/admin/purchase-returns/{$return['id']}/refund", ['resolution' => 'refund', 'payment_method' => 'bank_transfer'], $this->auth)
        ->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.resolution', 'refund');

    $refund = collect($this->tenantJson('GET', "/api/admin/purchase-orders/{$po['id']}/payments", [], $this->auth)->assertOk()->json('data'))->firstWhere('purchase_return_id', $return['id']);
    expect($refund['amount_paid'])->toBe('-10000.0000');
    $this->tenantJson('PATCH', "/api/admin/supplier-payments/{$refund['id']}", ['notes' => 'x'], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'supplier_refund_locked');
    $this->tenantJson('GET', "/api/admin/purchase-orders/{$po['id']}/balance", [], $this->auth)->assertOk()->assertJsonPath('data.balance', '15000.0000');
    expect(ledger('supplier_payment:'.$refund['id']))->toEqualCanonicalizing(['cash_bank:debit' => '10000.0000', 'accounts_payable:credit' => '10000.0000']);

    $this->tenantJson('PATCH', "/api/admin/purchase-returns/{$return['id']}/close", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'closed');

    // Deleting a manual payment reverses it.
    $this->tenantJson('DELETE', "/api/admin/supplier-payments/{$payment['id']}", [], $this->auth)->assertOk();
    expect(ledger('supplier_payment_reversal:'.$payment['id'].':deleted'))->toHaveKey('accounts_payable:credit');
});

it('turns the best supplier quotation into a draft purchase order and emails suppliers', function (): void {
    $a = $this->tenantJson('POST', '/api/admin/suppliers', ['name' => 'Alpha', 'email' => 'alpha@supply.test'], $this->auth)->assertCreated()->json('data');
    $b = $this->tenantJson('POST', '/api/admin/suppliers', ['name' => 'Beta', 'email' => 'beta@supply.test'], $this->auth)->assertCreated()->json('data');

    $request = $this->tenantJson('POST', '/api/admin/quotation-requests', ['warehouse_id' => $this->main->id, 'respond_by' => now()->addWeek()->toDateString(),
        'items' => [['product_id' => $this->shoe->id, 'quantity' => 20], ['product_id' => $this->bag->id, 'quantity' => 10]]], $this->auth)
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

    $sent = $this->tenantJson('POST', "/api/admin/quotation-requests/{$request['id']}/send", ['supplier_ids' => [$a['id'], $b['id']]], $this->auth)
        ->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonCount(2, 'data.quotations')->json('data');

    tenancy()->initialize($this->tenant);
    Notification::assertSentTo(Supplier::query()->findOrFail($a['id']), TemplatedNotification::class,
        fn ($n): bool => $n->key === 'quotation_request.sent' && str_contains($n->body, 'Runner × 20') && str_contains($n->body, $request['request_number']));

    [$qa, $qb] = [collect($sent['quotations'])->firstWhere('supplier.id', $a['id']), collect($sent['quotations'])->firstWhere('supplier.id', $b['id'])];
    [$shoeLine, $bagLine] = [$sent['items'][0]['id'], $sent['items'][1]['id']];

    $this->tenantJson('PATCH', "/api/admin/supplier-quotations/{$qa['id']}/record", ['items' => [['quotation_request_item_id' => $shoeLine, 'unit_price' => '17500']]], $this->auth)
        ->assertOk()->assertJsonPath('data.status', 'received');
    // Alpha quoted one line only: it cannot be accepted yet.
    $this->tenantJson('PATCH', "/api/admin/supplier-quotations/{$qa['id']}/accept", [], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'quotation_incomplete');

    $this->tenantJson('PATCH', "/api/admin/supplier-quotations/{$qb['id']}/record", ['valid_until' => now()->addDays(10)->toDateString(),
        'items' => [['quotation_request_item_id' => $shoeLine, 'unit_price' => '17000', 'lead_time_days' => 5], ['quotation_request_item_id' => $bagLine, 'unit_price' => '3900']]], $this->auth)->assertOk();

    $accepted = $this->tenantJson('PATCH', "/api/admin/supplier-quotations/{$qb['id']}/accept", [], $this->auth)->assertOk()
        ->assertJsonPath('data.quotation.status', 'accepted')
        ->assertJsonPath('data.purchase_order.status', 'draft')
        ->assertJsonPath('data.purchase_order.supplier.name', 'Beta')
        ->assertJsonPath('data.purchase_order.total', '379000.0000')->json('data');

    $this->tenantJson('GET', "/api/admin/quotation-requests/{$request['id']}", [], $this->auth)->assertOk()
        ->assertJsonPath('data.status', 'converted')
        ->assertJsonPath('data.quotations.0.status', 'rejected')
        ->assertJsonPath('data.quotations.1.purchase_order_id', $accepted['purchase_order']['id']);
});

it('holds a large purchase order for approval and cancels it on rejection', function (): void {
    $this->tenantJson('POST', '/api/admin/modules/approval_workflows/enable', [], $this->auth)->assertOk();
    $manager = User::query()->create(['name' => 'Manager', 'email' => 'manager@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $manager->assignRole('manager');
    $managerAuth = ['Authorization' => 'Bearer '.$manager->createToken('t', ['staff'])->plainTextToken];

    $this->tenantJson('POST', '/api/admin/approval-workflows', ['name' => 'Big purchases', 'module_key' => 'purchase_order', 'trigger_conditions' => ['min_amount' => 100000],
        'steps' => [['name' => 'Manager', 'approvers' => [['approver_type' => 'role', 'role_id' => Role::query()->where('name', 'manager')->value('id')]]]]], $this->auth)->assertCreated();

    $supplier = $this->tenantJson('POST', '/api/admin/suppliers', ['name' => 'Gamma'], $this->auth)->assertCreated()->json('data');

    // Small: no approval, receivable at once.
    $small = poFor($supplier['id'], [['product_id' => $this->bag->id, 'quantity' => 2, 'unit_cost' => '4000']]);
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$small['id']}/submit", [], $this->auth)->assertOk();
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$small['id']}/receive", ['items' => [['purchase_order_item_id' => $small['items'][0]['id'], 'quantity' => 2]]], $this->auth)->assertOk();

    // Large: receiving waits for the approval; a rejection cancels it.
    $large = poFor($supplier['id'], [['product_id' => $this->shoe->id, 'quantity' => 10, 'unit_cost' => '18000']]);
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$large['id']}/submit", [], $this->auth)->assertOk();
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$large['id']}/receive", ['items' => [['purchase_order_item_id' => $large['items'][0]['id'], 'quantity' => 1]]], $this->auth)
        ->assertStatus(409)->assertJsonPath('meta.error_code', 'approval_pending');

    tenancy()->initialize($this->tenant);
    $approval = ApprovalRequest::query()->where('approvable_type', 'purchase_order')->where('approvable_id', $large['id'])->firstOrFail();
    $this->tenantJson('POST', "/api/admin/approvals/{$approval->id}/reject", ['note' => 'Too many'], $managerAuth)->assertOk();
    $this->tenantJson('GET', "/api/admin/purchase-orders/{$large['id']}", [], $this->auth)->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('prices a purchase order in another currency at the rate captured on submit', function (): void {
    $landlord = DB::connection('landlord');
    $landlord->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);
    $landlord->table('currencies')->insert(['country_id' => 1, 'name' => 'USD', 'code' => 'USD', 'symbol' => '$', 'symbol_native' => '$']);

    $supplier = $this->tenantJson('POST', '/api/admin/suppliers', ['name' => 'Shenzhen Parts'], $this->auth)->assertCreated()->json('data');
    $po = poFor($supplier['id'], [['product_id' => $this->bag->id, 'quantity' => 100, 'unit_cost' => '2.50']], ['currency_code' => 'USD']);

    // No USD rate yet: it cannot be submitted.
    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$po['id']}/submit", [], $this->auth)->assertStatus(422)->assertJsonPath('meta.error_code', 'exchange_rate_unavailable');

    $this->tenantJson('POST', '/api/admin/modules/multi_currency/enable', [], $this->auth)->assertOk();
    $this->tenantJson('POST', '/api/admin/currencies', ['currency_code' => 'USD', 'rate' => '0.000625'], $this->auth)->assertCreated(); // ₦1,600 per $1

    $this->tenantJson('PATCH', "/api/admin/purchase-orders/{$po['id']}/submit", [], $this->auth)->assertOk()->assertJsonPath('data.exchange_rate_used', '1600.000000000000');
    $this->tenantJson('POST', "/api/admin/suppliers/{$supplier['id']}/payments", ['purchase_order_id' => $po['id'], 'amount_paid' => '100', 'payment_method' => 'bank_transfer'], $this->auth)
        ->assertCreated()->assertJsonPath('data.currency_code', 'USD');

    // $250 ordered − $100 paid = $150, which is ₦240,000 on the supplier's (base) balance.
    $this->tenantJson('GET', "/api/admin/purchase-orders/{$po['id']}/balance", [], $this->auth)->assertOk()->assertJsonPath('data.balance', '150.0000');
    $this->tenantJson('GET', "/api/admin/suppliers/{$supplier['id']}/balance", [], $this->auth)->assertOk()->assertJsonPath('data.balance', '240000.0000');
});
