<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Metrics;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\SupplierPaymentService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use App\Shared\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The purchasing section (spec §44.3): open purchase orders now, the value
 * received in the range (receipt movements at their base-currency unit
 * cost, D-100), what is owed to suppliers now, and the top suppliers by
 * value received.
 */
final readonly class PurchasingMetrics
{
    public function __construct(
        private TenantSettingsService $settings,
        private SupplierPaymentService $payments,
    ) {}

    public function purchasing(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
        $comparison = $range->comparison();
        $open = PurchaseOrder::query()->whereIn('status', [PurchaseOrder::SUBMITTED, PurchaseOrder::PARTIALLY_RECEIVED])->count();
        $owed = $this->payments->getOutstandingBalances()->reduce(static fn (string $sum, array $row): string => Money::add($sum, $row['balance']), Money::normalize(0));

        return new SectionResult(
            kpis: [
                KpiValue::count('open_purchase_orders', 'Open purchase orders', $open, $range, null, KpiValue::NEUTRAL),
                KpiValue::money('received_value', 'Received value', $this->received($range), $currency, $range,
                    $comparison === null ? null : $this->received($comparison), KpiValue::NEUTRAL),
                KpiValue::money('supplier_balance', 'Owed to suppliers', $owed, $currency, $range, null, KpiValue::DOWN_IS_GOOD),
            ],
            tables: [$this->topSuppliers($range, $currency)],
        );
    }

    /**
     * The purchase-orders list strip (§44.4): open (submitted), awaiting
     * receipt (submitted or partly received), received value (range) and
     * what is owed to suppliers.
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
        $owed = $this->payments->getOutstandingBalances()->reduce(static fn (string $sum, array $row): string => Money::add($sum, $row['balance']), Money::normalize(0));

        return [
            KpiValue::count('open', 'Open', PurchaseOrder::query()->where('status', PurchaseOrder::SUBMITTED)->count(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('awaiting_receipt', 'Awaiting receipt', PurchaseOrder::query()->whereIn('status', [PurchaseOrder::SUBMITTED, PurchaseOrder::PARTIALLY_RECEIVED])->count(), $range, null, KpiValue::NEUTRAL),
            KpiValue::money('received_value', 'Received value', $this->received($range), $currency, $range, null, KpiValue::NEUTRAL),
            KpiValue::money('outstanding_balance', 'Outstanding balance', $owed, $currency, $range, null, KpiValue::DOWN_IS_GOOD),
        ];
    }

    private function received(DateRange $range): string
    {
        return bcadd((string) ($this->receipts($range)->sum(DB::raw('m.quantity_delta * m.unit_cost_snapshot')) ?: '0'), '0', 4);
    }

    private function topSuppliers(DateRange $range, string $currency): TableBlock
    {
        $rows = $this->receipts($range)
            ->join('purchase_orders as po', static fn ($j) => $j->on('po.id', '=', 'm.reference_id')->where('m.reference_type', 'purchase_order'))
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->groupBy('s.id', 's.name')
            ->selectRaw('s.id, s.name, COUNT(DISTINCT po.id) as orders, SUM(m.quantity_delta * m.unit_cost_snapshot) as spend')
            ->orderByDesc('spend')->limit(TableBlock::MAX_ROWS)->get()
            ->map(static fn ($r): array => [
                'supplier_id' => (int) $r->id,
                'name' => $r->name,
                'orders' => (int) $r->orders,
                'spend' => bcadd((string) $r->spend, '0', 4),
                'currency_code' => $currency,
            ])->all();

        return new TableBlock('top_suppliers', 'Top suppliers', [
            ['key' => 'name', 'label' => 'Supplier', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => KpiValue::COUNT],
            ['key' => 'spend', 'label' => 'Received value', 'format' => 'money'],
        ], $rows, '/admin/suppliers');
    }

    private function receipts(DateRange $range): Builder
    {
        return DB::connection('tenant')->table('inventory_movements as m')->where('m.movement_type', 'purchase_receipt')
            ->whereBetween('m.created_at', [$range->startUtc(), $range->endUtc()]);
    }
}
