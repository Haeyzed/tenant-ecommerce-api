<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Metrics;

use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Metrics\OrderQueries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue figures (spec §44.2 "Catalogue and inventory"). Counts are
 * point-in-time ("now"), so they carry no comparison (§22.1).
 */
final readonly class CatalogMetrics
{
    public function __construct(private InventoryService $inventory) {}

    public function catalogue(DateRange $range, MetricsScope $scope): SectionResult
    {
        $products = $this->productCounts();
        $categories = $this->categoryCounts();
        $stock = $this->inventory->stockCounts($scope->warehouses());

        return new SectionResult(
            kpis: [
                KpiValue::count('products', 'Products', $products['total'], $range, null, KpiValue::NEUTRAL),
                KpiValue::count('active_products', 'Active', $products['active'], $range, null, KpiValue::NEUTRAL),
                KpiValue::count('inactive_products', 'Inactive', $products['inactive'], $range, null, KpiValue::NEUTRAL),
                KpiValue::count('categories', 'Categories', $categories['total'], $range, null, KpiValue::NEUTRAL),
                KpiValue::count('brands', 'Brands', DB::connection('tenant')->table('brands')->count(), $range, null, KpiValue::NEUTRAL),
                KpiValue::count('low_stock', 'Low stock', $stock['low'], $range, null, KpiValue::DOWN_IS_GOOD),
                KpiValue::count('out_of_stock', 'Out of stock', $stock['out'], $range, null, KpiValue::DOWN_IS_GOOD),
            ],
            tables: [$this->topProducts($range, $scope), $this->dataQuality()],
        );
    }

    /**
     * The products list KPI strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function productsStrip(DateRange $range, MetricsScope $scope): array
    {
        $products = $this->productCounts();
        $stock = $this->inventory->stockCounts($scope->warehouses());

        return [
            KpiValue::count('products', 'Products', $products['total'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('active_products', 'Active', $products['active'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('inactive_products', 'Inactive', $products['inactive'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('low_stock', 'Low stock', $stock['low'], $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('out_of_stock', 'Out of stock', $stock['out'], $range, null, KpiValue::DOWN_IS_GOOD),
        ];
    }

    /**
     * The categories list KPI strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function categoriesStrip(DateRange $range, MetricsScope $scope): array
    {
        $c = $this->categoryCounts();

        return [
            KpiValue::count('categories', 'Categories', $c['total'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('active_categories', 'Active', $c['active'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('categories_with_products', 'With products', $c['with_products'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('empty_categories', 'Empty', $c['total'] - $c['with_products'], $range, null, KpiValue::DOWN_IS_GOOD),
        ];
    }

    /**
     * Products not deleted; active = storefront-visible (is_active and
     * moderation allows, §50.3); inactive = is_active false.
     *
     * @return array{total: int, active: int, inactive: int}
     */
    private function productCounts(): array
    {
        $row = DB::connection('tenant')->table('products')->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as total,'
                ." SUM(CASE WHEN is_active = 1 AND moderation_status IN ('not_required', 'approved') THEN 1 ELSE 0 END) as active,"
                .' SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive')
            ->first();

        return ['total' => (int) ($row->total ?? 0), 'active' => (int) ($row->active ?? 0), 'inactive' => (int) ($row->inactive ?? 0)];
    }

    /**
     * @return array{total: int, active: int, with_products: int}
     */
    private function categoryCounts(): array
    {
        $row = DB::connection('tenant')->table('categories as c')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN c.is_active = 1 THEN 1 ELSE 0 END) as active')
            ->selectRaw('SUM(CASE WHEN EXISTS (SELECT 1 FROM product_categories pc JOIN products p ON p.id = pc.product_id AND p.deleted_at IS NULL WHERE pc.category_id = c.id) THEN 1 ELSE 0 END) as with_products')
            ->first();

        return ['total' => (int) ($row->total ?? 0), 'active' => (int) ($row->active ?? 0), 'with_products' => (int) ($row->with_products ?? 0)];
    }

    /**
     * Best sellers by units in the range.
     */
    private function topProducts(DateRange $range, MetricsScope $scope): TableBlock
    {
        $rows = OrderQueries::includedLines($scope)
            ->whereBetween('o.confirmed_at', [$range->startUtc(), $range->endUtc()])
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->groupBy('p.id', 'p.name', 'p.sku')
            ->selectRaw('p.id, p.name, p.sku, SUM(oi.quantity) as units')
            ->orderByDesc('units')
            ->limit(TableBlock::MAX_ROWS)
            ->get()
            ->map(static fn ($r): array => ['product_id' => (int) $r->id, 'name' => $r->name, 'sku' => $r->sku, 'units' => bcadd((string) $r->units, '0', 3)])
            ->all();

        return new TableBlock('top_products', 'Top products by units', [
            ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
            ['key' => 'sku', 'label' => 'SKU', 'format' => 'text'],
            ['key' => 'units', 'label' => 'Units', 'format' => 'quantity'],
        ], $rows, '/admin/products');
    }

    /**
     * Products needing attention: no image, or no price (zero).
     */
    private function dataQuality(): TableBlock
    {
        $morph = (new Product)->getMorphClass();
        $noImage = static fn (Builder $q) => $q->from('media')->whereColumn('media.model_id', 'p.id')->where('media.model_type', $morph)
            ->whereIn('media.collection_name', ['featured', 'gallery']);

        $rows = DB::connection('tenant')->table('products as p')->whereNull('p.deleted_at')
            ->where(static fn (Builder $q) => $q->whereNotExists($noImage)->orWhere('p.price', '<=', 0))
            ->orderBy('p.name')
            ->limit(TableBlock::MAX_ROWS)
            ->get(['p.id', 'p.name', 'p.price'])
            ->map(static fn ($r): array => [
                'product_id' => (int) $r->id,
                'name' => $r->name,
                'issue' => bccomp((string) $r->price, '0', 4) <= 0 ? 'no_price' : 'no_image',
            ])->all();

        return new TableBlock('catalogue_data_quality', 'Products without an image or price', [
            ['key' => 'name', 'label' => 'Product', 'format' => 'text'],
            ['key' => 'issue', 'label' => 'Issue', 'format' => 'text'],
        ], $rows, '/admin/products');
    }
}
