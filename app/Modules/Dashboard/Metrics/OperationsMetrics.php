<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Metrics;

use App\Modules\Tenancy\Enums\DomainStatus;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Platform operations (spec §22.5 "Operations", §22.6). Host health comes
 * from infrastructure monitoring, not the application (§77.7).
 */
final class OperationsMetrics
{
    /**
     * Alert thresholds for the current wait of each queue, in seconds
     * (§77.3). tenant-bulk has none: it is throttled by design.
     */
    public const array QUEUE_WAIT_THRESHOLDS = [
        'tenant-critical' => 30,
        'tenant-default' => 300,
        'landlord-default' => 300,
    ];

    public const array QUEUES = ['landlord-default', 'tenant-critical', 'tenant-default', 'tenant-bulk'];

    private const float SERVER_BUSY = 0.8;

    /**
     * Landlord webhook failures in 24 hours from which the alert becomes
     * critical.
     */
    private const int WEBHOOK_SPIKE = 10;

    private const int SNAPSHOT_FRESH_DAYS = 7;

    public function operations(DateRange $range): SectionResult
    {
        $waits = $this->queueWaits();

        return new SectionResult(
            kpis: [
                KpiValue::count('pending_provisioning', 'Pending provisioning', $this->tenantsIn(TenantStatus::Provisioning), $range, null, KpiValue::DOWN_IS_GOOD),
                KpiValue::count('failed_provisioning', 'Failed provisioning', $this->tenantsIn(TenantStatus::ProvisioningFailed), $range, null, KpiValue::DOWN_IS_GOOD),
                KpiValue::count('failed_jobs', 'Failed jobs (24 hours)', $this->failedJobs(), $range, null, KpiValue::DOWN_IS_GOOD),
                KpiValue::count('landlord_webhook_failures', 'Landlord webhook failures', $this->webhookFailures(), $range, null, KpiValue::DOWN_IS_GOOD),
                ...$this->tenantFailureKpis($range),
                KpiValue::count('storage_used_mb', 'Storage used (MB)', $this->storageUsedMb(), $range, null, KpiValue::NEUTRAL),
                KpiValue::count('servers_above_80', 'Database servers above 80%', count(array_filter($this->servers(), static fn (array $s): bool => $s['utilisation'] >= self::SERVER_BUSY * 100)), $range, null, KpiValue::DOWN_IS_GOOD),
            ],
            charts: [
                new ChartSeries('queue_wait', 'Current queue wait', ChartSeries::BAR, KpiValue::DURATION, [[
                    'key' => 'wait_seconds',
                    'label' => 'Wait (seconds)',
                    'points' => array_map(static fn (string $q, ?int $w): array => ['x' => $q, 'y' => $w], array_keys($waits), array_values($waits)),
                ]]),
            ],
            tables: [$this->serverTable(), $this->misconfiguredDomains()],
        );
    }

    /**
     * @return list<Alert>
     */
    public function alerts(): array
    {
        $alerts = [];

        $failed = $this->tenantsIn(TenantStatus::ProvisioningFailed);

        if ($failed > 0) {
            $alerts[] = new Alert('provisioning_failed', Alert::CRITICAL, "{$failed} tenant(s) failed provisioning.", $failed, '/admin/tenants?status=provisioning_failed');
        }

        $webhooks = $this->webhookFailures(now()->subDay());

        if ($webhooks > 0) {
            $alerts[] = new Alert('webhook_failures', $webhooks >= self::WEBHOOK_SPIKE ? Alert::CRITICAL : Alert::WARNING,
                "{$webhooks} landlord webhook(s) failed in the last 24 hours.", $webhooks, '/admin/payment-transactions');
        }

        foreach ($this->queueWaits() as $queue => $wait) {
            $threshold = self::QUEUE_WAIT_THRESHOLDS[$queue] ?? null;

            if ($threshold !== null && $wait !== null && $wait > $threshold) {
                $alerts[] = new Alert('queue_wait_'.$queue, Alert::CRITICAL, "The {$queue} queue has waited {$wait}s (threshold {$threshold}s).", $wait);
            }
        }

        return $alerts;
    }

    /**
     * Seconds the oldest pending job of each queue has waited; null when
     * the queue driver cannot tell (or is unreachable).
     *
     * @return array<string, int|null>
     */
    public function queueWaits(): array
    {
        $waits = [];

        foreach (self::QUEUES as $queue) {
            try {
                $created = Queue::connection()->creationTimeOfOldestPendingJob($queue);
                $waits[$queue] = $created === null ? 0 : max(0, now()->getTimestamp() - (int) $created);
            } catch (Throwable $e) {
                report($e);
                $waits[$queue] = null;
            }
        }

        return $waits;
    }

    private function tenantsIn(TenantStatus $status): int
    {
        return DB::connection('landlord')->table('tenants')->where('status', $status->value)->count();
    }

    private function failedJobs(): int
    {
        return DB::connection('landlord')->table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
    }

    /**
     * Landlord webhook_logs rows with an error and no processed_at.
     */
    private function webhookFailures(?DateTimeInterface $since = null): int
    {
        return DB::connection('landlord')->table('webhook_logs')
            ->whereNotNull('error')
            ->whereNull('processed_at')
            ->when($since !== null, static fn ($q) => $q->where('received_at', '>=', $since))
            ->count();
    }

    /**
     * Tenant webhook failures and failed accounting postings: Σ of each
     * tenant's latest snapshot (§22.5).
     *
     * @return list<KpiValue>
     */
    private function tenantFailureKpis(DateRange $range): array
    {
        $totals = DB::connection('landlord')->table('tenant_usage_snapshots as s')
            ->joinSub($this->latestSnapshots(), 'l', static fn ($j) => $j->on('l.tenant_id', '=', 's.tenant_id')->on('l.latest_date', '=', 's.date'))
            ->selectRaw('COALESCE(SUM(s.webhook_failures), 0) as webhooks, COALESCE(SUM(s.failed_postings), 0) as postings')
            ->first();

        return [
            KpiValue::count('tenant_webhook_failures', 'Tenant webhook failures', (int) ($totals->webhooks ?? 0), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('failed_postings', 'Failed accounting postings', (int) ($totals->postings ?? 0), $range, null, KpiValue::DOWN_IS_GOOD),
        ];
    }

    /**
     * Σ storage (MB) of each tenant's latest snapshot.
     */
    public function storageUsedMb(): int
    {
        return (int) DB::connection('landlord')->table('tenant_usage_snapshots as s')
            ->joinSub($this->latestSnapshots(), 'l', static fn ($j) => $j->on('l.tenant_id', '=', 's.tenant_id')->on('l.latest_date', '=', 's.date'))
            ->sum(DB::raw("CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(s.usage, '$.max_storage_mb')), '0') AS UNSIGNED)"));
    }

    private function latestSnapshots(): Builder
    {
        return DB::connection('landlord')->table('tenant_usage_snapshots')
            ->where('date', '>=', now()->subDays(self::SNAPSHOT_FRESH_DAYS)->toDateString())
            ->groupBy('tenant_id')
            ->selectRaw('tenant_id, MAX(date) as latest_date');
    }

    /**
     * @return list<array{id: int, name: string, tenant_count: int, max_tenants: int, utilisation: float, is_accepting_tenants: bool}>
     */
    private function servers(): array
    {
        return DB::connection('landlord')->table('database_servers')
            ->orderBy('name')
            ->get(['id', 'name', 'tenant_count', 'max_tenants', 'is_accepting_tenants'])
            ->map(static fn ($s): array => [
                'id' => (int) $s->id,
                'name' => (string) $s->name,
                'tenant_count' => (int) $s->tenant_count,
                'max_tenants' => (int) $s->max_tenants,
                'utilisation' => $s->max_tenants > 0 ? round($s->tenant_count / $s->max_tenants * 100, 1) : 100.0,
                'is_accepting_tenants' => (bool) $s->is_accepting_tenants,
            ])->all();
    }

    private function serverTable(): TableBlock
    {
        $servers = $this->servers();
        usort($servers, static fn (array $a, array $b): int => $b['utilisation'] <=> $a['utilisation']);

        return new TableBlock('database_server_utilisation', 'Database server utilisation', [
            ['key' => 'name', 'label' => 'Server', 'format' => 'text'],
            ['key' => 'tenant_count', 'label' => 'Tenants', 'format' => 'count'],
            ['key' => 'max_tenants', 'label' => 'Capacity', 'format' => 'count'],
            ['key' => 'utilisation', 'label' => 'Utilisation', 'format' => 'percent'],
        ], $servers, '/admin/database-servers');
    }

    private function misconfiguredDomains(): TableBlock
    {
        $rows = DB::connection('landlord')->table('domains')
            ->join('tenants', 'tenants.id', '=', 'domains.tenant_id')
            ->where(static fn ($q) => $q->where('domains.status', DomainStatus::Misconfigured->value)->orWhere('domains.tls_status', 'failed'))
            ->orderByDesc('domains.updated_at')
            ->limit(TableBlock::MAX_ROWS)
            ->get(['domains.id', 'domains.domain', 'domains.status', 'domains.tls_status', 'domains.failure_reason', 'tenants.id as tenant_id', 'tenants.name as tenant'])
            ->map(static fn ($d): array => [
                'id' => (int) $d->id,
                'domain' => $d->domain,
                'tenant_id' => $d->tenant_id,
                'tenant' => $d->tenant,
                'status' => $d->status,
                'tls_status' => $d->tls_status,
                'failure_reason' => $d->failure_reason,
            ])->all();

        return new TableBlock('misconfigured_domains', 'Misconfigured custom domains', [
            ['key' => 'domain', 'label' => 'Domain', 'format' => 'text'],
            ['key' => 'tenant', 'label' => 'Tenant', 'format' => 'text'],
            ['key' => 'status', 'label' => 'Status', 'format' => 'status'],
            ['key' => 'tls_status', 'label' => 'TLS', 'format' => 'status'],
        ], $rows);
    }
}
