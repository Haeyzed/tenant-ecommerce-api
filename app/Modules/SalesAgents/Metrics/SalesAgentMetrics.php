<?php

declare(strict_types=1);

namespace App\Modules\SalesAgents\Metrics;

use App\Modules\SalesAgents\Models\SalesAgentCommission;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use Illuminate\Support\Facades\DB;

/**
 * The sales-agents section (spec §44.3): commissions pending, approved and
 * paid in the range (earned_at, approved_at and paid_at respectively), and
 * the top agents by commission earned. Amounts are in the base currency.
 */
final readonly class SalesAgentMetrics
{
    public function __construct(private TenantSettingsService $settings) {}

    public function salesAgents(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
        $comparison = $range->comparison();

        return new SectionResult(
            kpis: [
                KpiValue::money('commissions_pending', 'Commissions pending', $this->sum(SalesAgentCommission::PENDING, 'earned_at', $range), $currency, $range,
                    $comparison === null ? null : $this->sum(SalesAgentCommission::PENDING, 'earned_at', $comparison), KpiValue::NEUTRAL),
                KpiValue::money('commissions_approved', 'Commissions approved', $this->sum(SalesAgentCommission::APPROVED, 'approved_at', $range), $currency, $range,
                    $comparison === null ? null : $this->sum(SalesAgentCommission::APPROVED, 'approved_at', $comparison), KpiValue::NEUTRAL),
                KpiValue::money('commissions_paid', 'Commissions paid', $this->sum(SalesAgentCommission::PAID, 'paid_at', $range), $currency, $range,
                    $comparison === null ? null : $this->sum(SalesAgentCommission::PAID, 'paid_at', $comparison), KpiValue::NEUTRAL),
            ],
            tables: [$this->topAgents($range, $currency)],
        );
    }

    private function sum(string $status, string $column, DateRange $range): string
    {
        $sum = DB::connection('tenant')->table('sales_agent_commissions')->where('status', $status)
            ->whereBetween($column, [$range->startUtc(), $range->endUtc()])->sum('commission_amount');

        return bcadd((string) ($sum ?: '0'), '0', 4);
    }

    private function topAgents(DateRange $range, string $currency): TableBlock
    {
        $rows = DB::connection('tenant')->table('sales_agent_commissions as c')->join('sales_agents as a', 'a.id', '=', 'c.sales_agent_id')
            ->whereBetween('c.earned_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('a.id', 'a.name', 'a.agent_code')
            ->selectRaw('a.id, a.name, a.agent_code, COUNT(*) as orders, SUM(c.gross_amount) as sales, SUM(c.commission_amount) as commission')
            ->orderByDesc('commission')->limit(TableBlock::MAX_ROWS)->get()
            ->map(static fn ($r): array => [
                'sales_agent_id' => (int) $r->id,
                'name' => $r->name,
                'agent_code' => $r->agent_code,
                'orders' => (int) $r->orders,
                'sales' => bcadd((string) $r->sales, '0', 4),
                'commission' => bcadd((string) $r->commission, '0', 4),
                'currency_code' => $currency,
            ])->all();

        return new TableBlock('top_agents', 'Top agents', [
            ['key' => 'name', 'label' => 'Agent', 'format' => 'text'],
            ['key' => 'orders', 'label' => 'Orders', 'format' => KpiValue::COUNT],
            ['key' => 'sales', 'label' => 'Sales', 'format' => 'money'],
            ['key' => 'commission', 'label' => 'Commission', 'format' => 'money'],
        ], $rows, '/admin/sales-agents');
    }
}
