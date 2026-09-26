<?php

declare(strict_types=1);

namespace App\Modules\Billing\Metrics;

use App\Modules\Billing\Models\PaymentTransaction;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use App\Shared\Metrics\TimeSeries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Platform payment outcomes (spec §22.5 "Revenue and payments"): live
 * charge attempts by status, dated by created_at; refunds and chargebacks
 * dated by paid_at.
 */
final readonly class PaymentMetrics
{
    /**
     * A provider "spikes" when, over the last 24 hours, it has at least
     * this many failed live charges and they are at least this share of
     * its decided charges.
     */
    private const int SPIKE_MIN_FAILURES = 5;

    private const string SPIKE_MIN_RATE = '30';

    public function payments(DateRange $range): SectionResult
    {
        $comparison = $range->comparison();
        $byStatus = TimeSeries::aggregate($this->charges(), 'created_at', $range, 'COUNT(*)', 'status');
        $empty = array_fill_keys($range->buckets(), '0');
        $successful = $byStatus[PaymentTransaction::SUCCESSFUL] ?? $empty;
        $failed = $byStatus[PaymentTransaction::FAILED] ?? $empty;
        $pending = $byStatus[PaymentTransaction::PENDING] ?? $empty;
        $previous = $comparison === null ? null : TimeSeries::total($this->charges(), 'created_at', $comparison, 'COUNT(*)', 'status');
        $prev = static fn (string $status): ?int => $previous === null ? null : (int) ($previous[$status] ?? 0);

        $reversals = $this->reversalCounts($range);
        $prevReversals = $comparison === null ? null : $this->reversalCounts($comparison);

        $s = TimeSeries::sum($successful);
        $f = TimeSeries::sum($failed);

        return new SectionResult(
            kpis: [
                KpiValue::count('successful_payments', 'Successful payments', (int) $s, $range, $prev(PaymentTransaction::SUCCESSFUL), KpiValue::UP_IS_GOOD, TimeSeries::sparkline($successful, true)),
                KpiValue::count('failed_payments', 'Failed payments', (int) $f, $range, $prev(PaymentTransaction::FAILED), KpiValue::DOWN_IS_GOOD, TimeSeries::sparkline($failed, true)),
                KpiValue::count('pending_payments', 'Pending payments', (int) TimeSeries::sum($pending), $range, $prev(PaymentTransaction::PENDING), KpiValue::NEUTRAL),
                KpiValue::count('refunded_payments', 'Refunds', $reversals['refund'], $range, $prevReversals['refund'] ?? null, KpiValue::DOWN_IS_GOOD),
                KpiValue::count('chargebacks', 'Chargebacks', $reversals['chargeback'], $range, $prevReversals['chargeback'] ?? null, KpiValue::DOWN_IS_GOOD),
                KpiValue::rate('payment_success_rate', 'Payment success rate', $s, bcadd($s, $f, 0), $range,
                    $previous === null ? null : [(string) $prev(PaymentTransaction::SUCCESSFUL), (string) ($prev(PaymentTransaction::SUCCESSFUL) + $prev(PaymentTransaction::FAILED))]),
            ],
            charts: [
                new ChartSeries('success_rate_over_time', 'Payment success rate', ChartSeries::LINE, KpiValue::PERCENT, [[
                    'key' => 'success_rate',
                    'label' => 'Success rate',
                    'points' => array_map(
                        static fn (string $bucket): array => ['x' => $bucket, 'y' => KpiValue::percentOf($successful[$bucket], bcadd($successful[$bucket], $failed[$bucket], 4))],
                        array_keys($successful),
                    ),
                ]], $range->interval),
                $this->volumeByProvider($range),
            ],
            tables: [$this->recentFailures()],
        );
    }

    /**
     * The failed-payments KPI of the subscriptions section.
     */
    public function failedCharges(DateRange $range): SectionResult
    {
        $comparison = $range->comparison();
        $failed = TimeSeries::aggregate((clone $this->charges())->where('status', PaymentTransaction::FAILED), 'created_at', $range, 'COUNT(*)')['']
            ?? array_fill_keys($range->buckets(), '0');

        return new SectionResult(kpis: [
            KpiValue::count('failed_payments', 'Failed payments', (int) TimeSeries::sum($failed), $range,
                $comparison === null ? null : (int) (TimeSeries::total((clone $this->charges())->where('status', PaymentTransaction::FAILED), 'created_at', $comparison, 'COUNT(*)')[''] ?? 0),
                KpiValue::DOWN_IS_GOOD, TimeSeries::sparkline($failed, true)),
        ]);
    }

    /**
     * @return list<Alert>
     */
    public function alerts(): array
    {
        $rows = (clone $this->charges())
            ->where('created_at', '>=', now()->subDay())
            ->whereIn('status', [PaymentTransaction::SUCCESSFUL, PaymentTransaction::FAILED])
            ->groupBy('provider')
            ->selectRaw("provider, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed, COUNT(*) as decided")
            ->get();

        $alerts = [];

        foreach ($rows as $row) {
            $rate = KpiValue::percentOf((string) $row->failed, (string) $row->decided);

            if ((int) $row->failed >= self::SPIKE_MIN_FAILURES && $rate !== null && bccomp($rate, self::SPIKE_MIN_RATE, 1) >= 0) {
                $alerts[] = new Alert('provider_failure_spike', Alert::CRITICAL,
                    ucfirst((string) $row->provider)." failed {$row->failed} of {$row->decided} charges ({$rate}%) in the last 24 hours.",
                    (int) $row->failed, '/admin/payment-transactions?status=failed&provider='.$row->provider);
            }
        }

        return $alerts;
    }

    /**
     * The payment-transactions list KPI strip (§22.4).
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range): array
    {
        $comparison = $range->comparison();
        $current = TimeSeries::total($this->charges(), 'created_at', $range, 'COUNT(*)', 'status');
        $previous = $comparison === null ? null : TimeSeries::total($this->charges(), 'created_at', $comparison, 'COUNT(*)', 'status');
        $reversals = $this->reversalCounts($range);
        $prevReversals = $comparison === null ? null : $this->reversalCounts($comparison);
        $s = (string) (int) ($current[PaymentTransaction::SUCCESSFUL] ?? 0);
        $f = (string) (int) ($current[PaymentTransaction::FAILED] ?? 0);
        $ps = $previous === null ? null : (int) ($previous[PaymentTransaction::SUCCESSFUL] ?? 0);
        $pf = $previous === null ? null : (int) ($previous[PaymentTransaction::FAILED] ?? 0);

        return [
            KpiValue::count('successful_charges', 'Successful charges', (int) $s, $range, $ps),
            KpiValue::count('failed_charges', 'Failed charges', (int) $f, $range, $pf, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('refunds', 'Refunds', $reversals['refund'], $range, $prevReversals['refund'] ?? null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('chargebacks', 'Chargebacks', $reversals['chargeback'], $range, $prevReversals['chargeback'] ?? null, KpiValue::DOWN_IS_GOOD),
            KpiValue::rate('payment_success_rate', 'Payment success rate', $s, (string) ((int) $s + (int) $f), $range,
                $ps === null ? null : [(string) $ps, (string) ($ps + (int) $pf)]),
        ];
    }

    /**
     * Successful live refunds and chargebacks paid in the range.
     *
     * @return array{refund: int, chargeback: int}
     */
    private function reversalCounts(DateRange $range): array
    {
        $counts = DB::connection('landlord')->table('payment_transactions')
            ->whereIn('type', [PaymentTransaction::REFUND, PaymentTransaction::CHARGEBACK])
            ->where('mode', 'live')
            ->where('status', PaymentTransaction::SUCCESSFUL)
            ->whereBetween('paid_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) as aggregate')
            ->pluck('aggregate', 'type');

        return ['refund' => (int) ($counts['refund'] ?? 0), 'chargeback' => (int) ($counts['chargeback'] ?? 0)];
    }

    private function volumeByProvider(DateRange $range): ChartSeries
    {
        $rows = (clone $this->charges())
            ->whereBetween('created_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('provider', 'status')
            ->selectRaw('provider, status, COUNT(*) as aggregate')
            ->get();

        $providers = $rows->pluck('provider')->unique()->sort()->values()->all();
        $series = [];

        foreach ([PaymentTransaction::SUCCESSFUL, PaymentTransaction::FAILED, PaymentTransaction::PENDING] as $status) {
            $series[] = [
                'key' => $status,
                'label' => ucfirst($status),
                'points' => array_map(static fn (string $provider): array => [
                    'x' => $provider,
                    'y' => (int) ($rows->first(static fn ($r): bool => $r->provider === $provider && $r->status === $status)->aggregate ?? 0),
                ], $providers),
            ];
        }

        return new ChartSeries('volume_by_provider', 'Charges by provider', ChartSeries::STACKED_BAR, KpiValue::COUNT, $series);
    }

    private function recentFailures(): TableBlock
    {
        $rows = (clone $this->charges())
            ->join('tenants', 'tenants.id', '=', 'payment_transactions.tenant_id')
            ->where('payment_transactions.status', PaymentTransaction::FAILED)
            ->orderByDesc('payment_transactions.created_at')
            ->limit(TableBlock::MAX_ROWS)
            ->get(['payment_transactions.id', 'payment_transactions.tenant_id', 'tenants.name', 'payment_transactions.amount', 'payment_transactions.currency_code', 'payment_transactions.provider', 'payment_transactions.failure_reason', 'payment_transactions.created_at'])
            ->map(static fn ($r): array => [
                'id' => (int) $r->id,
                'tenant_id' => $r->tenant_id,
                'tenant' => $r->name,
                'amount' => bcadd((string) $r->amount, '0', 4),
                'currency_code' => $r->currency_code,
                'provider' => $r->provider,
                'failure_reason' => $r->failure_reason,
                'created_at' => CarbonImmutable::parse($r->created_at, 'UTC')->toIso8601String(),
            ])->all();

        return new TableBlock('recent_failed_payments', 'Recent failed payments', [
            ['key' => 'tenant', 'label' => 'Tenant', 'format' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
            ['key' => 'provider', 'label' => 'Provider', 'format' => 'text'],
            ['key' => 'failure_reason', 'label' => 'Reason', 'format' => 'text'],
            ['key' => 'created_at', 'label' => 'Attempted', 'format' => 'datetime'],
        ], $rows, '/admin/payment-transactions?status=failed');
    }

    /**
     * Live charge attempts.
     */
    private function charges(): Builder
    {
        return DB::connection('landlord')->table('payment_transactions')
            ->where('payment_transactions.type', PaymentTransaction::CHARGE)
            ->where('payment_transactions.mode', 'live');
    }
}
