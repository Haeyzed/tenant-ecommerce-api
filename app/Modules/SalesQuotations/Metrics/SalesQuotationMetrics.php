<?php

declare(strict_types=1);

namespace App\Modules\SalesQuotations\Metrics;

use App\Modules\SalesQuotations\Models\SalesQuotation;
use App\Modules\SalesQuotations\Models\SalesQuotationRequest;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Illuminate\Support\Facades\DB;

/**
 * The sales-quotations section (spec §44.3): quotations sent in the range
 * (sent_at), accepted in the range (responded_at), the conversion rate
 * (accepted of those sent in the range) and the accepted value: the
 * converted orders' base-currency amounts.
 */
final readonly class SalesQuotationMetrics
{
    public function __construct(private TenantSettingsService $settings) {}

    public function salesQuotations(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
        $comparison = $range->comparison();
        $sent = $this->sent($range);
        $converted = DB::connection('tenant')->table('sales_quotations')->where('status', SalesQuotation::ACCEPTED)
            ->whereBetween('sent_at', [$range->startUtc(), $range->endUtc()])->count();

        return new SectionResult(
            kpis: [
                KpiValue::count('quotations_sent', 'Sent', $sent, $range, $comparison === null ? null : $this->sent($comparison), KpiValue::UP_IS_GOOD),
                KpiValue::count('quotations_accepted', 'Accepted', $this->accepted($range)['count'], $range,
                    $comparison === null ? null : $this->accepted($comparison)['count'], KpiValue::UP_IS_GOOD),
                KpiValue::rate('conversion_rate', 'Conversion rate', (string) $converted, (string) $sent, $range),
                KpiValue::money('accepted_value', 'Accepted value', $this->accepted($range)['value'], $currency, $range,
                    $comparison === null ? null : $this->accepted($comparison)['value']),
            ],
        );
    }

    /**
     * The quotation-requests list strip (§44.4): requests awaiting a quote,
     * quotes sent and accepted in the range, and the conversion rate.
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        $sent = $this->sent($range);
        $converted = DB::connection('tenant')->table('sales_quotations')->where('status', SalesQuotation::ACCEPTED)
            ->whereBetween('sent_at', [$range->startUtc(), $range->endUtc()])->count();

        return [
            KpiValue::count('awaiting_quote', 'Awaiting quote', DB::connection('tenant')->table('sales_quotation_requests')
                ->whereIn('status', [SalesQuotationRequest::DRAFT, SalesQuotationRequest::SENT])->count(), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('sent', 'Sent', $sent, $range),
            KpiValue::count('accepted', 'Accepted', $this->accepted($range)['count'], $range),
            KpiValue::rate('conversion_rate', 'Conversion rate', (string) $converted, (string) $sent, $range),
        ];
    }

    private function sent(DateRange $range): int
    {
        return DB::connection('tenant')->table('sales_quotations')->whereNotNull('sent_at')
            ->whereBetween('sent_at', [$range->startUtc(), $range->endUtc()])->count();
    }

    /**
     * @return array{count: int, value: string}
     */
    private function accepted(DateRange $range): array
    {
        $row = DB::connection('tenant')->table('sales_quotations as q')->join('orders as o', 'o.id', '=', 'q.converted_order_id')
            ->where('q.status', SalesQuotation::ACCEPTED)->whereBetween('q.responded_at', [$range->startUtc(), $range->endUtc()])
            ->selectRaw('COUNT(*) as accepted, SUM(o.base_currency_amount) as value')->first();

        return ['count' => (int) ($row->accepted ?? 0), 'value' => bcadd((string) ($row->value ?? '0'), '0', 4)];
    }
}
