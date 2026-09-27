<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Metrics;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use App\Shared\Metrics\TimeSeries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Inventory figures (spec §44.2 "Catalogue and inventory"), within the
 * staff warehouse scope. Stock figures are "now"; movements, adjustments
 * and received transfers are flows over the range.
 */
final readonly class InventoryMetrics
{
    public function __construct(
        private InventoryService $inventory,
        private TenantSettingsService $settings,
    ) {}

    /**
     * The low-stock card of the overview.
     */
    public function overview(DateRange $range, MetricsScope $scope): SectionResult
    {
        return new SectionResult(kpis: [
            KpiValue::count('low_stock', 'Low stock', $this->inventory->stockCounts($scope->warehouses())['low'], $range, null, KpiValue::DOWN_IS_GOOD),
        ]);
    }

    public function inventory(DateRange $range, MetricsScope $scope): SectionResult
    {
        $warehouses = $scope->warehouses();
        $stock = $this->inventory->stockCounts($warehouses);
        $value = $this->inventoryValue($warehouses);
        $adjustments = TimeSeries::total($this->adjustments($warehouses), 'submitted_at', $range, 'COUNT(*)')[''] ?? '0';
        $comparison = $range->comparison();

        return new SectionResult(
            kpis: [
                KpiValue::count('total_skus', 'Total SKUs', $this->totalSkus(), $range, null, KpiValue::NEUTRAL),
                KpiValue::money('inventory_value', 'Inventory value', $value['value'], $this->currency(), $range, null, KpiValue::NEUTRAL, null, false,
                    $value['uncosted'] > 0 ? "At current cost; {$value['uncosted']} items without a cost are excluded" : 'At current cost'),
                KpiValue::count('low_stock', 'Low stock', $stock['low'], $range, null, KpiValue::DOWN_IS_GOOD),
                KpiValue::count('out_of_stock', 'Out of stock', $stock['out'], $range, null, KpiValue::DOWN_IS_GOOD),
                KpiValue::count('pending_transfers', 'Pending transfers', $this->transfers($warehouses)->where('status', StockTransfer::IN_TRANSIT)->count(), $range, null, KpiValue::NEUTRAL),
                KpiValue::count('stock_adjustments', 'Adjustments', (int) $adjustments, $range,
                    $comparison === null ? null : (int) (TimeSeries::total($this->adjustments($warehouses), 'submitted_at', $comparison, 'COUNT(*)')[''] ?? 0), KpiValue::NEUTRAL),
            ],
            charts: [$this->movements($range, $warehouses)],
            tables: [$this->lowStockTable($warehouses)],
        );
    }

    /**
     * The inventory list KPI strip (§44.4), optionally for one warehouse.
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope, ?int $warehouseId = null): array
    {
        $warehouses = $scope->warehouses($warehouseId);
        $stock = $this->inventory->stockCounts($warehouses);

        return [
            KpiValue::count('total_skus', 'Total SKUs', $this->totalSkus(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('low_stock', 'Low stock', $stock['low'], $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('out_of_stock', 'Out of stock', $stock['out'], $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::money('inventory_value', 'Inventory value', $this->inventoryValue($warehouses)['value'], $this->currency(), $range, null, KpiValue::NEUTRAL, null, false, 'At current cost'),
            KpiValue::count('pending_transfers', 'Pending transfers', $this->transfers($warehouses)->where('status', StockTransfer::IN_TRANSIT)->count(), $range, null, KpiValue::NEUTRAL),
        ];
    }

    /**
     * The stock-transfers list KPI strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function transfersStrip(DateRange $range, MetricsScope $scope): array
    {
        $warehouses = $scope->warehouses();
        $comparison = $range->comparison();
        $received = fn (DateRange $r): int => (int) (TimeSeries::total($this->transfers($warehouses)->where('status', StockTransfer::RECEIVED), 'received_at', $r, 'COUNT(*)')[''] ?? 0);

        return [
            KpiValue::count('draft_transfers', 'Draft', $this->transfers($warehouses)->where('status', StockTransfer::DRAFT)->count(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('in_transit_transfers', 'In transit', $this->transfers($warehouses)->where('status', StockTransfer::IN_TRANSIT)->count(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('received_transfers', 'Received', $received($range), $range, $comparison === null ? null : $received($comparison), KpiValue::NEUTRAL),
        ];
    }

    /**
     * @return list<Alert>
     */
    public function alerts(MetricsScope $scope): array
    {
        $stock = $this->inventory->stockCounts($scope->warehouses());
        $alerts = [];

        if ($stock['out'] > 0) {
            $alerts[] = new Alert('out_of_stock', Alert::WARNING, "{$stock['out']} ".($stock['out'] === 1 ? 'item is' : 'items are').' out of stock.', $stock['out'], '/admin/inventory/out-of-stock');
        }

        if ($stock['low'] > 0) {
            $alerts[] = new Alert('low_stock', Alert::INFO, "{$stock['low']} ".($stock['low'] === 1 ? 'item is' : 'items are').' running low.', $stock['low'], '/admin/inventory/low-stock');
        }

        return $alerts;
    }

    /**
     * Active simple, bundle, digital and service products plus active
     * variants of active variable products.
     */
    private function totalSkus(): int
    {
        $products = DB::connection('tenant')->table('products')->whereNull('deleted_at')->where('is_active', true)
            ->whereIn('product_type', [Product::SIMPLE, Product::BUNDLE, Product::DIGITAL, Product::SERVICE])->count();
        $variants = DB::connection('tenant')->table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereNull('v.deleted_at')->where('v.is_active', true)->whereNull('p.deleted_at')->where('p.is_active', true)
            ->where('p.product_type', Product::VARIABLE)->count();

        return $products + $variants;
    }

    /**
     * Σ quantity × current cost (variant, else product); rows without a
     * cost are excluded and counted.
     *
     * @param  list<int>|null  $warehouses
     * @return array{value: string, uncosted: int}
     */
    private function inventoryValue(?array $warehouses): array
    {
        $query = DB::connection('tenant')->table('inventory as i')
            ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'i.product_variant_id')
            ->whereNull('p.deleted_at')
            ->where('i.quantity', '>', 0);

        $warehouses === null ? $query->where('w.is_active', true) : $query->whereIn('i.warehouse_id', $warehouses === [] ? [0] : $warehouses);

        $row = $query->selectRaw('SUM(CASE WHEN COALESCE(v.cost_price, p.cost_price) IS NOT NULL THEN i.quantity * COALESCE(v.cost_price, p.cost_price) ELSE 0 END) as value,'
            .' SUM(CASE WHEN COALESCE(v.cost_price, p.cost_price) IS NULL THEN 1 ELSE 0 END) as uncosted')->first();

        return ['value' => bcadd((string) ($row->value ?? '0'), '0', 4), 'uncosted' => (int) ($row->uncosted ?? 0)];
    }

    /**
     * @param  list<int>|null  $warehouses
     */
    private function movements(DateRange $range, ?array $warehouses): ChartSeries
    {
        $query = DB::connection('tenant')->table('inventory_movements');

        if ($warehouses !== null) {
            $query->whereIn('warehouse_id', $warehouses === [] ? [0] : $warehouses);
        }

        $series = [];

        foreach (TimeSeries::aggregate($query, 'created_at', $range, 'COUNT(*)', 'movement_type') as $type => $points) {
            $series[] = ['key' => $type, 'label' => ucfirst(str_replace('_', ' ', $type)), 'points' => TimeSeries::points($points, true)];
        }

        return new ChartSeries('stock_movements', 'Stock movements by type', ChartSeries::STACKED_BAR, KpiValue::COUNT, $series, $range->interval);
    }

    /**
     * @param  list<int>|null  $warehouses
     */
    private function lowStockTable(?array $warehouses): TableBlock
    {
        $rows = collect($this->inventory->getLowStockProducts($warehouses, ['per_page' => TableBlock::MAX_ROWS])->items())
            ->map(static fn ($r): array => [
                'product_id' => (int) $r->product_id,
                'product_variant_id' => $r->product_variant_id === null ? null : (int) $r->product_variant_id,
                'name' => $r->product_name,
                'sku' => $r->sku,
                'available' => bcadd((string) $r->available, '0', 3),
            ])->all();

        return new TableBlock('low_stock', 'Low stock', [
            ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
            ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
            ['key' => 'available', 'label' => 'Available', 'format' => 'quantity'],
        ], $rows, '/admin/inventory/low-stock');
    }

    /**
     * @param  list<int>|null  $warehouses
     */
    private function transfers(?array $warehouses): Builder
    {
        $query = DB::connection('tenant')->table('stock_transfers');

        if ($warehouses !== null) {
            $ids = $warehouses === [] ? [0] : $warehouses;
            $query->where(static fn (Builder $q) => $q->whereIn('from_warehouse_id', $ids)->orWhereIn('to_warehouse_id', $ids));
        }

        return $query;
    }

    /**
     * Submitted stock adjustments.
     *
     * @param  list<int>|null  $warehouses
     */
    private function adjustments(?array $warehouses): Builder
    {
        $query = DB::connection('tenant')->table('stock_adjustments')->whereNotNull('submitted_at');

        return $warehouses === null ? $query : $query->whereIn('warehouse_id', $warehouses === [] ? [0] : $warehouses);
    }

    private function currency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }
}
