<?php

declare(strict_types=1);

namespace App\Modules\Installments\Metrics;

use App\Modules\Installments\Models\InstallmentPayment;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Illuminate\Support\Facades\DB;

/**
 * The installments section (spec §44.3): plans running now, the balance
 * still to collect on them (plans in the base currency), overdue
 * installments and defaulted plans.
 */
final readonly class InstallmentMetrics
{
    public function __construct(private TenantSettingsService $settings) {}

    public function installments(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
        $plans = DB::connection('tenant')->table('installment_plans')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $outstanding = DB::connection('tenant')->table('installment_payments as ip')
            ->join('installment_plans as pl', 'pl.id', '=', 'ip.installment_plan_id')
            ->whereIn('pl.status', [InstallmentPlan::ACTIVE, InstallmentPlan::DEFAULTED])->where('pl.currency_code', $currency)
            ->where('ip.status', '!=', InstallmentPayment::PAID)
            ->sum(DB::raw('ip.amount_due - ip.amount_paid'));

        return new SectionResult(
            kpis: [
                KpiValue::count('active_plans', 'Active plans', (int) ($plans[InstallmentPlan::ACTIVE] ?? 0), $range, null, KpiValue::NEUTRAL),
                KpiValue::money('outstanding_balance', 'Outstanding balance', bcadd((string) ($outstanding ?: '0'), '0', 4), $currency, $range, null, KpiValue::NEUTRAL),
                KpiValue::count('overdue_installments', 'Overdue installments', DB::connection('tenant')->table('installment_payments')
                    ->where('status', InstallmentPayment::OVERDUE)->count(), $range, null, KpiValue::DOWN_IS_GOOD),
                KpiValue::count('defaulted_plans', 'Defaulted plans', (int) ($plans[InstallmentPlan::DEFAULTED] ?? 0), $range, null, KpiValue::DOWN_IS_GOOD),
            ],
        );
    }
}
