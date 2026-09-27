<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Metrics;

use App\Modules\Shipping\Models\Shipment;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\TimeSeries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shipment figures for the shipments list (spec §44.4): pending, in transit
 * and failed now; delivered by delivered_at over the range. Shipments of
 * test orders are excluded; staff see their warehouses' shipments.
 */
final readonly class ShipmentMetrics
{
    /**
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        $now = $this->shipments($scope)->groupBy('s.status')->selectRaw('s.status, COUNT(*) as aggregate')->pluck('aggregate', 'status');
        $delivered = fn (DateRange $r): int => (int) (TimeSeries::total($this->shipments($scope)->where('s.status', Shipment::DELIVERED), 's.delivered_at', $r, 'COUNT(*)')[''] ?? 0);
        $comparison = $range->comparison();

        return [
            KpiValue::count('pending_shipments', 'Pending', (int) ($now[Shipment::PENDING] ?? 0), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('in_transit_shipments', 'In transit', (int) ($now[Shipment::DISPATCHED] ?? 0) + (int) ($now[Shipment::IN_TRANSIT] ?? 0), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('delivered_shipments', 'Delivered', $delivered($range), $range, $comparison === null ? null : $delivered($comparison)),
            KpiValue::count('failed_shipments', 'Failed', (int) ($now[Shipment::FAILED] ?? 0), $range, null, KpiValue::DOWN_IS_GOOD),
        ];
    }

    private function shipments(MetricsScope $scope): Builder
    {
        return $scope->column(DB::connection('tenant')->table('shipments as s')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('o.is_test', false), 's.warehouse_id');
    }
}
