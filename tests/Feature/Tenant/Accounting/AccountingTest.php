<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPostingRequest;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccountsService;
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
use App\Modules\Payments\Services\OrderPaymentLedgerService;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = $this->createTenant('a');
    $this->subscribe($this->tenant, 'standard');

    tenancy()->initialize($this->tenant);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Tenant);
    app(TenantSettingsService::class)->set('default_currency', 'NGN');
    app(ChartOfAccountsService::class)->seedDefaults();
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->staff = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];

    app(WarehouseService::class)->ensureDefault();
    $this->main = Warehouse::query()->firstOrFail();
    $this->shoe = Product::query()->create(['name' => 'Runner', 'price' => '40', 'cost_price' => '25', 'is_active' => true]);
    app(InventoryService::class)->adjustStock($this->main, $this->shoe, null, '20', 'adjustment_in');
});

function enableAccounting(): void
{
    test()->tenantJson('POST', '/api/admin/modules/accounting/enable', [], test()->staff)->assertOk()->assertJsonPath('data.state', 'enabled');
}

function openYear(): void
{
    $year = now()->year;
    test()->tenantJson('POST', '/api/admin/accounting/fiscal-years', ['name' => "FY{$year}", 'starts_on' => "{$year}-01-01", 'ends_on' => "{$year}-12-31"], test()->staff)
        ->assertCreated()->assertJsonCount(12, 'data.periods');
}

/**
 * Two shoes at 40 less 10 discount, 5 shipping, 5 tax: total 80.
 */
function ledgerOrder(bool $isTest = false): Order
{
    tenancy()->initialize(test()->tenant);

    return app(OrderService::class)->createOrder([
        'currency_code' => 'NGN',
        'is_test' => $isTest,
        'guest_token' => 'guest-token-ledger-0123456789abcdef0123',
        'customer_name' => 'Ada Obi',
        'customer_email' => 'ada@shop.test',
        'lines' => [['product' => test()->shoe, 'variant' => null, 'warehouse' => test()->main, 'quantity' => '2', 'unit_price' => '40', 'price_source' => 'base',
            'discount_amount' => '10', 'tax_rate_applied' => '7.1429', 'tax_amount' => '5', 'line_total' => '75']],
        'totals' => ['subtotal' => '80', 'discount_amount' => '10', 'shipping_amount' => '5', 'tax_amount' => '5', 'total' => '80'],
        'shipping_address' => ['name' => 'Ada Obi', 'line1' => '1 Marina', 'country_id' => 1],
    ]);
}

function pay(Order $order, string $amount): OrderPayment
{
    return app(OrderPaymentLedgerService::class)->recordPayment($order, ['payment_method' => 'cash', 'amount' => $amount], test()->owner);
}

/**
 * @return array<string, string> system_key => signed balance (debit − credit)
 */
function balances(): array
{
    tenancy()->initialize(test()->tenant);
    $rows = DB::connection('tenant')->table('journal_entry_lines as l')->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
        ->groupBy('a.system_key')
        ->selectRaw("a.system_key, SUM(CASE WHEN l.type = 'debit' THEN l.amount ELSE -l.amount END) as net")
        ->pluck('net', 'system_key');

    return $rows->map(static fn ($v): string => bcadd((string) $v, '0', 4))->all();
}

it('posts sales, payments, refunds and cancellations as balanced entries, never for test orders', function (): void {
    enableAccounting();
    openYear();

    // Rehearsals never reach the ledger (§40.8).
    pay(ledgerOrder(true), '80');
    expect(AccountingPostingRequest::query()->count())->toBe(0);

    $order = ledgerOrder();
    pay($order, '80');

    tenancy()->initialize($this->tenant);
    expect(AccountingPostingRequest::query()->pluck('status', 'posting_key')->all())
        ->toBe(['order_payment:'.OrderPayment::query()->where('order_id', $order->id)->value('id') => 'posted', 'order_sale:'.$order->id => 'posted']);

    $sale = JournalEntry::query()->where('posting_key', 'order_sale:'.$order->id)->with('lines.account')->firstOrFail();
    $lines = $sale->lines->mapWithKeys(static fn ($l): array => [$l->account->system_key.':'.$l->type => (string) $l->amount])->all();
    expect($lines)->toEqual([
        'sales_revenue:credit' => '70.0000', 'shipping_revenue:credit' => '5.0000', 'tax_payable:credit' => '5.0000',
        'accounts_receivable:debit' => '80.0000', 'cost_of_goods_sold:debit' => '50.0000', 'inventory_asset:credit' => '50.0000',
    ]);

    // A direct refund: net of the order's tax share (40 × 5/80 = 2.50).
    app(OrderPaymentService::class)->refundOrder($order->refresh(), '40', 'Damaged', $this->owner);
    $b = balances();
    expect($b['sales_returns'])->toBe('37.5000')->and($b['tax_payable'])->toBe('-2.5000')->and($b['cash_bank'])->toBe('40.0000')
        ->and($b['accounts_receivable'])->toBe('0.0000');

    // Cancelling a confirmed order reverses its sale.
    $second = ledgerOrder();
    pay($second, '80');
    app(OrderService::class)->cancelOrder($second->refresh(), 'Customer changed mind');
    tenancy()->initialize($this->tenant);
    expect(JournalEntry::query()->where('posting_key', 'order_sale:'.$second->id)->value('reversed_at'))->not->toBeNull()
        ->and(JournalEntry::query()->where('posting_key', 'order_sale_reversal:'.$second->id)->value('source'))->toBe('reversal');

    $trial = $this->tenantJson('GET', '/api/admin/accounting/reports/trial-balance', [], $this->staff)->assertOk()->json('data');
    expect($trial['balanced'])->toBeTrue();

    $sheet = $this->tenantJson('GET', '/api/admin/accounting/reports/balance-sheet', [], $this->staff)->assertOk()->json('data');
    expect($sheet['balanced'])->toBeTrue();

    $pl = $this->tenantJson('GET', '/api/admin/accounting/reports/profit-and-loss', [], $this->staff)->assertOk()->json('data');
    // Revenue 70 + 5 − 37.50 returns; expenses: cost of goods 50.
    expect($pl['revenue']['total'])->toBe('37.5000')->and($pl['expenses']['total'])->toBe('50.0000')->and($pl['net_profit'])->toBe('-12.5000');

    $cash = Account::query()->where('system_key', 'cash_bank')->value('id');
    $ledger = $this->tenantJson('GET', "/api/admin/accounting/reports/general-ledger/{$cash}", [], $this->staff)->assertOk()->json('data');
    expect($ledger['opening_balance'])->toBe('0.0000')->and(end($ledger['lines'])['balance'])->toBe('120.0000');

    $flow = $this->tenantJson('GET', '/api/admin/accounting/reports/cash-flow', [], $this->staff)->assertOk()->json('data');
    expect($flow['categories']['operating']['inflow'])->toBe('160.0000')->and($flow['net_change'])->toBe('120.0000');
});

it('reverses and re-posts an edited manual payment, and reverses a deleted one', function (): void {
    enableAccounting();
    openYear();
    $order = ledgerOrder();
    $payment = pay($order, '50');

    $this->tenantJson('PATCH', "/api/admin/order-payments/{$payment->id}", ['amount' => '30'], $this->staff)->assertOk();
    expect(balances()['cash_bank'])->toBe('30.0000');

    tenancy()->initialize($this->tenant);
    expect(AccountingPostingRequest::query()->where('posting_key', 'order_payment:'.$payment->id.':v2')->value('status'))->toBe('posted');

    $this->tenantJson('DELETE', "/api/admin/order-payments/{$payment->id}", [], $this->staff)->assertOk();
    expect(balances()['cash_bank'])->toBe('0.0000')->and(balances()['accounts_receivable'])->toBe('0.0000');
});

it('fails a posting with no open period, tells admins, and posts on retry', function (): void {
    enableAccounting();
    $order = ledgerOrder();
    pay($order, '80');

    tenancy()->initialize($this->tenant);
    expect(AccountingPostingRequest::query()->where('status', 'failed')->count())->toBe(2)
        ->and(AccountingPostingRequest::query()->value('last_error'))->toContain('No fiscal period');
    Notification::assertSentTo($this->owner, TemplatedNotification::class, static fn ($n): bool => $n->key === 'accounting.posting_failed');

    openYear();
    $this->tenantJson('GET', '/api/admin/accounting/posting-requests?status=failed', [], $this->staff)->assertOk()->assertJsonCount(2, 'data');
    $this->tenantJson('POST', '/api/admin/accounting/posting-requests/retry', [], $this->staff)->assertOk()->assertJsonPath('data.dispatched', 2);

    tenancy()->initialize($this->tenant);
    expect(AccountingPostingRequest::query()->where('status', 'posted')->count())->toBe(2)
        ->and(JournalEntry::query()->where('posting_key', 'order_sale:'.$order->id)->value('entry_date'))->not->toBeNull();
});

it('takes balanced manual entries in open periods only, and reverses each entry once', function (): void {
    enableAccounting();
    openYear();
    tenancy()->initialize($this->tenant);
    $cash = Account::query()->where('system_key', 'cash_bank')->value('id');
    $general = Account::query()->where('system_key', 'general_expenses')->value('id');
    $today = now()->toDateString();

    $entry = fn (string $debit, string $credit) => ['entry_date' => $today, 'description' => 'Opening float', 'cash_flow_category' => 'financing', 'lines' => [
        ['account_id' => $cash, 'type' => 'debit', 'amount' => $debit], ['account_id' => $general, 'type' => 'credit', 'amount' => $credit],
    ]];

    $this->tenantJson('POST', '/api/admin/accounting/journal-entries', $entry('100', '90'), [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'posting_rejected');
    $posted = $this->tenantJson('POST', '/api/admin/accounting/journal-entries', $entry('100', '100'), [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertCreated()->assertJsonPath('data.source', 'manual')->assertJsonCount(2, 'data.lines')->json('data');

    $this->tenantJson('POST', "/api/admin/accounting/journal-entries/{$posted['id']}/reverse", ['reason' => 'Typo'], $this->staff)->assertCreated()->assertJsonPath('data.source', 'reversal');
    $this->tenantJson('POST', "/api/admin/accounting/journal-entries/{$posted['id']}/reverse", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'entry_not_reversible');
    expect(balances()['cash_bank'])->toBe('0.0000');

    // A closed period takes nothing; reopening is allowed while the year is open.
    tenancy()->initialize($this->tenant);
    $period = FiscalPeriod::query()->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->firstOrFail();
    $this->tenantJson('POST', "/api/admin/accounting/fiscal-periods/{$period->id}/close", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'closed');
    $this->tenantJson('POST', '/api/admin/accounting/journal-entries', $entry('5', '5'), [...$this->staff, 'Idempotency-Key' => Str::uuid()->toString()])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'posting_rejected');
    $year = $period->fiscal_year_id;
    $this->tenantJson('POST', "/api/admin/accounting/fiscal-years/{$year}/close", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'periods_open');
    $this->tenantJson('POST', "/api/admin/accounting/fiscal-periods/{$period->id}/reopen", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'open');
    $this->tenantJson('POST', '/api/admin/accounting/fiscal-years', ['name' => 'Overlap', 'starts_on' => now()->year.'-06-01', 'ends_on' => now()->year + 1 .'-05-31'], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'fiscal_year_overlap');
});

it('protects system accounts and keeps sub-accounts within their type', function (): void {
    enableAccounting();
    tenancy()->initialize($this->tenant);
    $cash = Account::query()->where('system_key', 'cash_bank')->firstOrFail();
    $revenue = Account::query()->where('system_key', 'sales_revenue')->firstOrFail();

    $this->tenantJson('GET', '/api/admin/accounting/accounts', [], $this->staff)->assertOk()->assertJsonCount(18, 'data');
    $this->tenantJson('POST', "/api/admin/accounting/accounts/{$cash->id}/deactivate", [], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'system_account');
    $this->tenantJson('PATCH', "/api/admin/accounting/accounts/{$cash->id}", ['code' => '1999'], $this->staff)->assertStatus(422);
    $this->tenantJson('PATCH', "/api/admin/accounting/accounts/{$cash->id}", ['name' => 'Main bank'], $this->staff)->assertOk()->assertJsonPath('data.name', 'Main bank');

    $this->tenantJson('POST', '/api/admin/accounting/accounts', ['code' => '1010', 'name' => 'POS Till', 'account_category_id' => $cash->account_category_id, 'parent_account_id' => $revenue->id], $this->staff)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'parent_type_mismatch');
    $till = $this->tenantJson('POST', '/api/admin/accounting/accounts', ['code' => '1010', 'name' => 'POS Till', 'account_category_id' => $cash->account_category_id, 'parent_account_id' => $cash->id], $this->staff)
        ->assertCreated()->assertJsonPath('data.is_system', false)->json('data');
    $this->tenantJson('POST', "/api/admin/accounting/accounts/{$till['id']}/deactivate", [], $this->staff)->assertOk()->assertJsonPath('data.is_active', false);

    // Seeding again changes nothing (insert-only).
    app(ChartOfAccountsService::class)->seedDefaults();
    expect(Account::query()->count())->toBe(19)->and(Account::query()->find($cash->id)->name)->toBe('Main bank');
});

it('serves the accounting and expenses sections, the expenses strip and the gated lookups', function (): void {
    // Before accounting is enabled: no section, and its lookups answer with the module's 403.
    expect(array_column($this->tenantJson('GET', '/api/admin/dashboard', [], $this->staff)->json('data.sections'), 'key'))->not->toContain('accounting');
    $this->tenantJson('GET', '/api/admin/lookups/chart-of-accounts', [], $this->staff)->assertForbidden();

    enableAccounting();
    openYear();
    pay(ledgerOrder(), '80');

    expect(array_column($this->tenantJson('GET', '/api/admin/dashboard', [], $this->staff)->json('data.sections'), 'key'))->toContain('accounting', 'expenses');
    $cards = collect($this->tenantJson('GET', '/api/admin/dashboard/accounting?range=today&compare=none', [], $this->staff)->assertOk()->json('data.kpis'))->keyBy('key');
    expect($cards['revenue']['value'])->toBe('75.0000')
        ->and($cards['expenses']['value'])->toBe('50.0000')
        ->and($cards['net_profit']['value'])->toBe('25.0000')
        ->and($cards['cash_balance']['value'])->toBe('80.0000')
        ->and($cards['receivables']['value'])->toBe('0.0000');

    $this->tenantJson('GET', '/api/admin/dashboard/expenses', [], $this->staff)->assertOk()->assertJsonPath('data.kpis.0.key', 'expenses');
    $this->tenantJson('GET', '/api/admin/expenses/metrics', [], $this->staff)->assertOk()->assertJsonCount(3, 'data.kpis');

    $this->tenantJson('GET', '/api/admin/lookups/chart-of-accounts?account_type=expense', [], $this->staff)->assertOk()->assertJsonCount(5, 'data');
    $this->tenantJson('GET', '/api/admin/lookups/account-types', [], $this->staff)->assertOk()->assertJsonCount(5, 'data');
    $year = $this->tenantJson('GET', '/api/admin/lookups/fiscal-years', [], $this->staff)->assertOk()->json('data.0.value');
    $this->tenantJson('GET', "/api/admin/lookups/fiscal-periods?fiscal_year_id={$year}", [], $this->staff)->assertOk()->assertJsonCount(12, 'data');
    $this->tenantJson('GET', '/api/admin/lookups/fiscal-periods', [], $this->staff)->assertStatus(422);
});

it('records expenses and income without accounting, and posts them once accounting is on', function (): void {
    $category = $this->tenantJson('POST', '/api/admin/expense-categories', ['name' => 'Rent'], $this->staff)->assertCreated()->json('data');
    $biller = $this->tenantJson('POST', '/api/admin/billers', ['name' => 'Landlord Ltd', 'category' => 'rent'], $this->staff)->assertCreated()->json('data');
    $rent = $this->tenantJson('POST', '/api/admin/expenses', ['expense_category_id' => $category['id'], 'biller_id' => $biller['id'], 'amount' => '250', 'expense_date' => now()->toDateString()], $this->staff)
        ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.currency_code', 'NGN')->json('data');
    $this->tenantJson('POST', '/api/admin/expenses', ['expense_category_id' => $category['id'], 'amount' => '5', 'expense_date' => now()->toDateString(), 'supplier_id' => 1], $this->staff)->assertStatus(422);

    // Accounting off: paying writes no posting, and enabling later does not back-post.
    $this->tenantJson('POST', "/api/admin/expenses/{$rent['id']}/mark-paid", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'paid');
    $this->tenantJson('PATCH', "/api/admin/expenses/{$rent['id']}", ['amount' => '1'], $this->staff)->assertStatus(422)->assertJsonPath('meta.error_code', 'expense_paid');
    tenancy()->initialize($this->tenant);
    expect(AccountingPostingRequest::query()->count())->toBe(0);

    enableAccounting();
    openYear();
    $light = $this->tenantJson('POST', '/api/admin/expenses', ['expense_category_id' => $category['id'], 'amount' => '40', 'expense_date' => now()->toDateString()], $this->staff)->json('data');
    $this->tenantJson('POST', "/api/admin/expenses/{$light['id']}/mark-paid", [], $this->staff)->assertOk();

    $source = $this->tenantJson('POST', '/api/admin/income-categories', ['name' => 'Interest'], $this->staff)->assertCreated()->json('data');
    $interest = $this->tenantJson('POST', '/api/admin/income', ['income_category_id' => $source['id'], 'amount' => '12.5', 'received_date' => now()->toDateString(), 'source' => 'Bank'], $this->staff)
        ->assertCreated()->json('data');
    $this->tenantJson('POST', "/api/admin/income/{$interest['id']}/mark-received", [], $this->staff)->assertOk()->assertJsonPath('data.status', 'received');
    $this->tenantJson('DELETE', "/api/admin/income/{$interest['id']}", [], $this->staff)->assertStatus(422);

    $b = balances();
    expect($b['general_expenses'])->toBe('40.0000')->and($b['other_income'])->toBe('-12.5000')->and($b['cash_bank'])->toBe('-27.5000');
    $this->tenantJson('GET', '/api/admin/expenses?status=paid', [], $this->staff)->assertOk()->assertJsonCount(2, 'data');
});
