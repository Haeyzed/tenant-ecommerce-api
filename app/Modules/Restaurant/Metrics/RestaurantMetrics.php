<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Metrics;

use App\Modules\Orders\Models\Order;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The restaurant section (spec §44.3): tables occupied and table orders
 * open now, and the average preparation time of dishes made ready in the
 * range, within the viewer's locations.
 */
final readonly class RestaurantMetrics
{
    public function restaurant(DateRange $range, MetricsScope $scope): SectionResult
    {
        $db = DB::connection('tenant');
        $floors = static fn ($q) => $q->whereIn('restaurant_floor_id', $db->table('restaurant_floors')->select('id')->whereIn('warehouse_id', $scope->warehouseIds ?: [0]));
        $tables = $db->table('restaurant_tables')->when($scope->isNarrowed(), $floors);
        $open = $db->table('orders')->whereNotNull('restaurant_table_id')->where('status', Order::PENDING)->whereNull('cancelled_at')
            ->when($scope->isNarrowed(), static fn ($q) => $q->whereIn('restaurant_table_id', $db->table('restaurant_tables')->select('id')->where($floors)));

        return new SectionResult(kpis: [
            KpiValue::count('occupied_tables', 'Occupied tables', (clone $tables)->where('status', RestaurantTable::OCCUPIED)->count(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('open_table_orders', 'Open table orders', $open->count(), $range, null, KpiValue::NEUTRAL),
            new KpiValue('average_preparation_time', 'Average preparation time', $this->preparationSeconds($range, $scope), KpiValue::DURATION),
        ]);
    }

    private function preparationSeconds(DateRange $range, MetricsScope $scope): ?int
    {
        $rows = DB::connection('tenant')->table('order_items')->whereNotNull('kitchen_ready_at')->whereBetween('kitchen_ready_at', [$range->startUtc(), $range->endUtc()])
            ->when($scope->isNarrowed(), static fn ($q) => $q->whereIn('warehouse_id', $scope->warehouseIds ?: [0]))
            ->get(['created_at', 'kitchen_ready_at']);

        if ($rows->isEmpty()) {
            return null;
        }

        return (int) round($rows->avg(static fn (object $r): int => (int) CarbonImmutable::parse($r->created_at)->diffInSeconds(CarbonImmutable::parse($r->kitchen_ready_at), true)));
    }
}
