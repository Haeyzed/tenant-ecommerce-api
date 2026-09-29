<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Support;

use App\Modules\Dashboard\Services\Tenant\TenantDashboardService;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Metrics\SalesMetrics;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierPaymentService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Metrics\DateRange;
use App\Shared\Support\Money;

/**
 * The assistant's answers (spec §62.2). Each calls the service the
 * dashboard or the equivalent screen uses, within the asking user's staff
 * scope, so the assistant and the dashboard report the same numbers. An
 * answer only restates what the service returned.
 */
final readonly class AssistantHandlers
{
    public function __construct(
        private SalesMetrics $sales,
        private TenantDashboardService $dashboard,
        private TenantSettingsService $settings,
        private OrderService $orders,
        private InventoryService $inventory,
        private FeatureAccessService $features,
    ) {}

    /**
     * @return array{answer: string, data: array<string, mixed>}
     */
    public function todaySales(User $user): array
    {
        $s = $this->sales->summary($this->today(), $this->dashboard->scope($user));
        $currency = $this->sales->currency();

        return [
            'answer' => $s['orders'] === 0 ? 'No orders have been confirmed today yet.'
                : "Today's net sales are ".Money::format($s['net_sales'], $currency)." from {$s['orders']} ".($s['orders'] === 1 ? 'order' : 'orders')
                    .' (average '.Money::format($s['average_order_value'], $currency).').',
            'data' => ['currency' => $currency, ...$s],
        ];
    }

    /**
     * Today's sales with whichever of pending orders, purchases and expenses
     * the user can see.
     *
     * @return array{answer: string, data: array<string, mixed>}
     */
    public function dailySnapshot(User $user): array
    {
        $parts = [$this->todaySales($user)];
        $parts[] = $this->pendingOrders($user);

        if ($this->enabled('purchasing') && $user->hasPermissionTo('purchase-orders.view', 'staff')) {
            $parts['purchases'] = $this->todayPurchases($user);
        }

        if ($this->enabled('expenses') && $user->hasPermissionTo('expenses.view', 'staff')) {
            $parts['expenses'] = $this->todayExpenses($user);
        }

        return [
            'answer' => implode(' ', array_column($parts, 'answer')),
            'data' => [
                'sales' => $parts[0]['data'],
                'pending_orders' => $parts[1]['data'],
                'purchases' => $parts['purchases']['data'] ?? null,
                'expenses' => $parts['expenses']['data'] ?? null,
            ],
        ];
    }

    /**
     * @return array{answer: string, data: array<string, mixed>}
     */
    public function topSellingProducts(User $user): array
    {
        // The last 30 days including today: the question is asked now.
        $today = $this->today()->from;
        $range = DateRange::fromInput(['range' => 'custom', 'from' => $today->subDays(29)->toDateString(), 'to' => $today->toDateString(), 'compare' => 'none'],
            (string) ($this->settings->get('timezone') ?: 'UTC'));
        $rows = $this->sales->topProductRows($range, $this->dashboard->scope($user), 5);

        return [
            'answer' => $rows === [] ? 'Nothing has sold in the last 30 days.'
                : 'Best sellers in the last 30 days: '.implode('; ', array_map(static fn (array $r): string => $r['name'].' ('.rtrim(rtrim($r['units'], '0'), '.').' sold, '
                    .Money::format($r['net_sales'], $r['currency_code']).')', $rows)).'.',
            'data' => ['period' => 'last_30_days', 'products' => $rows],
        ];
    }

    /**
     * @return array{answer: string, data: array<string, mixed>}
     */
    public function pendingOrders(User $user): array
    {
        $pending = $this->orders->getPendingOrders($user);

        return [
            'answer' => $pending['count'] === 0 ? 'There are no orders waiting to be fulfilled.'
                : "{$pending['count']} ".($pending['count'] === 1 ? 'order is' : 'orders are').' waiting to be fulfilled ('
                    .($pending['by_status'][Order::PENDING] ?? 0).' pending, '.($pending['by_status'][Order::PROCESSING] ?? 0).' processing).',
            'data' => [
                'count' => $pending['count'],
                'by_status' => $pending['by_status'],
                'orders' => array_map(static fn (Order $o): array => [
                    'id' => $o->id, 'order_number' => $o->order_number, 'status' => $o->status, 'payment_status' => $o->payment_status,
                    'customer_name' => $o->customer_name, 'total' => (string) $o->total, 'currency_code' => $o->currency_code, 'placed_at' => $o->placed_at?->toIso8601String(),
                ], $pending['orders']),
            ],
        ];
    }

    /**
     * @return array{answer: string, data: array<string, mixed>}
     */
    public function lowStock(User $user): array
    {
        $page = $this->inventory->getLowStockProducts($this->dashboard->scope($user)->warehouseIds, ['per_page' => 10]);

        return $this->stockAnswer($page->total(), $page->items(), 'running low', 'Nothing is running low on stock.');
    }

    /**
     * @return array{answer: string, data: array<string, mixed>}
     */
    public function outOfStock(User $user): array
    {
        $page = $this->inventory->getOutOfStockProducts($this->dashboard->scope($user)->warehouseIds, ['per_page' => 10]);

        return $this->stockAnswer($page->total(), $page->items(), 'out of stock', 'Nothing is out of stock.');
    }

    /**
     * Purchase orders dated today, sent or received (drafts and cancelled
     * orders are left out), in the base currency.
     *
     * @return array{answer: string, data: array<string, mixed>}
     */
    public function todayPurchases(User $user): array
    {
        $today = $this->todayDate();
        $orders = collect(app(PurchaseOrderService::class)->listPurchaseOrders(['from' => $today, 'to' => $today, 'per_page' => 100])->items())
            ->reject(static fn (PurchaseOrder $po): bool => in_array($po->status, [PurchaseOrder::DRAFT, PurchaseOrder::CANCELLED], true))->values();
        $currency = $this->sales->currency();
        $total = $orders->reduce(static fn (string $sum, PurchaseOrder $po): string => Money::add($sum, Money::mul($po->total(), $po->toBaseRate())), Money::normalize(0));

        return [
            'answer' => $orders->isEmpty() ? 'No purchase orders are dated today.'
                : $orders->count().' purchase '.($orders->count() === 1 ? 'order' : 'orders').' today, worth '.Money::format($total, $currency).'.',
            'data' => ['date' => $today, 'currency' => $currency, 'total' => $total, 'purchase_orders' => $orders->map(static fn (PurchaseOrder $po): array => [
                'id' => $po->id, 'po_number' => $po->po_number, 'supplier' => $po->supplier?->name, 'status' => $po->status, 'total' => $po->total(), 'currency_code' => $po->currency_code,
            ])->all()],
        ];
    }

    /**
     * @return array{answer: string, data: array<string, mixed>}
     */
    public function outstandingSupplierPayments(User $user): array
    {
        $balances = app(SupplierPaymentService::class)->getOutstandingBalances();
        $currency = $this->sales->currency();
        $total = $balances->reduce(static fn (string $sum, array $b): string => Money::add($sum, $b['balance']), Money::normalize(0));

        return [
            'answer' => $balances->isEmpty() ? 'You owe no supplier anything.'
                : 'You owe '.$balances->count().' '.($balances->count() === 1 ? 'supplier' : 'suppliers').' '.Money::format($total, $currency).' in total. Largest: '
                    .implode('; ', $balances->take(3)->map(static fn (array $b): string => $b['name'].' '.Money::format($b['balance'], $b['currency_code']))->all()).'.',
            'data' => ['currency' => $currency, 'total' => $total, 'suppliers' => $balances->take(10)->values()->all()],
        ];
    }

    /**
     * @return array{answer: string, data: array<string, mixed>}
     */
    public function todayExpenses(User $user): array
    {
        $today = app(ExpenseService::class)->getTodayExpenses();
        $count = count($today['expenses']);

        return [
            'answer' => $count === 0 ? 'No expenses are recorded for today.'
                : "{$count} ".($count === 1 ? 'expense' : 'expenses').' recorded today, totalling '.Money::format($today['total'], $today['currency']).'.',
            'data' => $today,
        ];
    }

    /**
     * @param  list<object>  $items
     * @return array{answer: string, data: array<string, mixed>}
     */
    private function stockAnswer(int $total, array $items, string $state, string $none): array
    {
        $names = array_map(static fn (object $r): string => $r->product_name.($r->product_variant_id !== null ? ' ('.$r->sku.')' : ''), $items);

        return [
            'answer' => $total === 0 ? $none
                : "{$total} ".($total === 1 ? 'item is' : 'items are')." {$state}".($names === [] ? '.' : ': '.implode(', ', array_slice($names, 0, 5)).($total > 5 ? ', and more.' : '.')),
            'data' => ['count' => $total, 'items' => array_map(static fn (object $r): array => [
                'product_id' => (int) $r->product_id, 'product_variant_id' => $r->product_variant_id === null ? null : (int) $r->product_variant_id,
                'name' => $r->product_name, 'sku' => $r->sku, 'available' => bcadd((string) $r->available, '0', 3), 'low_stock_threshold' => (int) $r->low_stock_threshold,
            ], $items)],
        ];
    }

    private function today(): DateRange
    {
        return DateRange::fromInput(['range' => 'today', 'compare' => 'none'], (string) ($this->settings->get('timezone') ?: 'UTC'));
    }

    private function todayDate(): string
    {
        return $this->today()->from->toDateString();
    }

    private function enabled(string $feature): bool
    {
        $tenant = tenant();

        return $tenant instanceof Tenant && $this->features->state($tenant, $feature) === ModuleState::Enabled;
    }
}
