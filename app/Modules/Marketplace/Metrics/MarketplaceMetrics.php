<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Metrics;

use App\Modules\Marketplace\Models\Seller;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The marketplace section (spec §44.3): approved sellers and products
 * awaiting moderation now, seller sales in the range (ledger gross, net of
 * reversals, by entry time) and what is payable to sellers now (unpaid net),
 * with the top sellers. Seller figures are never summed with agent
 * commissions (§52).
 */
final readonly class MarketplaceMetrics
{
    public function __construct(private TenantSettingsService $settings) {}

    public function marketplace(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
        $comparison = $range->comparison();
        $payable = DB::connection('tenant')->table('seller_ledger_entries')->whereNull('seller_payout_id')->sum('net_payable');

        return new SectionResult(
            kpis: [
                KpiValue::count('approved_sellers', 'Approved sellers', Seller::query()->where('status', Seller::APPROVED)->count(), $range, null, KpiValue::UP_IS_GOOD),
                KpiValue::count('products_awaiting_moderation', 'Products awaiting approval',
                    DB::connection('tenant')->table('products')->whereNotNull('seller_id')->where('moderation_status', 'pending')->whereNull('deleted_at')->count(), $range, null, KpiValue::DOWN_IS_GOOD),
                KpiValue::money('seller_sales', 'Seller sales', $this->sales($range), $currency, $range, $comparison === null ? null : $this->sales($comparison)),
                KpiValue::money('payable_to_sellers', 'Payable to sellers', bcadd((string) ($payable ?: '0'), '0', 4), $currency, $range, null, KpiValue::NEUTRAL),
            ],
            tables: [$this->topSellers($range, $currency)],
        );
    }

    /**
     * The sellers list strip (§44.4): approved, pending and suspended
     * sellers, and the unpaid balance owed to sellers.
     *
     * @return list<KpiValue>
     */
    public function sellersStrip(DateRange $range, MetricsScope $scope): array
    {
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
        $counts = Seller::query()->groupBy('status')->selectRaw('status, COUNT(*) as total')->pluck('total', 'status');
        $payable = DB::connection('tenant')->table('seller_ledger_entries')->whereNull('seller_payout_id')->sum('net_payable');

        return [
            KpiValue::count('approved', 'Approved', (int) ($counts[Seller::APPROVED] ?? 0), $range, null, KpiValue::UP_IS_GOOD),
            KpiValue::count('pending', 'Pending', (int) ($counts[Seller::PENDING] ?? 0), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('suspended', 'Suspended', (int) ($counts[Seller::SUSPENDED] ?? 0), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::money('payable_balance', 'Payable balance', bcadd((string) ($payable ?: '0'), '0', 4), $currency, $range, null, KpiValue::NEUTRAL),
        ];
    }

    private function sales(DateRange $range): string
    {
        return bcadd((string) ($this->entries($range)->sum('e.gross_amount') ?: '0'), '0', 4);
    }

    private function topSellers(DateRange $range, string $currency): TableBlock
    {
        $rows = $this->entries($range)->join('sellers as s', 's.id', '=', 'e.seller_id')
            ->groupBy('s.id', 's.business_name')
            ->selectRaw('s.id, s.business_name as name, COUNT(DISTINCT e.order_id) as orders, SUM(e.gross_amount) as sales, SUM(e.commission_amount) as commission')
            ->orderByDesc('sales')->limit(TableBlock::MAX_ROWS)->get()
            ->map(static fn ($r): array => [
                'seller_id' => (int) $r->id,
                'name' => $r->name,
                'orders' => (int) $r->orders,
                'sales' => bcadd((string) $r->sales, '0', 4),
                'commission' => bcadd((string) $r->commission, '0', 4),
                'currency_code' => $currency,
            ])->all();

        return new TableBlock('top_sellers', 'Top sellers', [
            ['key' => 'name', 'label' => 'Seller', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => KpiValue::COUNT],
            ['key' => 'sales', 'label' => 'Sales', 'format' => 'money'],
            ['key' => 'commission', 'label' => 'Commission', 'format' => 'money'],
        ], $rows, '/admin/sellers');
    }

    private function entries(DateRange $range): Builder
    {
        return DB::connection('tenant')->table('seller_ledger_entries as e')->whereBetween('e.created_at', [$range->startUtc(), $range->endUtc()]);
    }
}
