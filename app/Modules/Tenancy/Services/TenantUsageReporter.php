<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUsageSnapshot;
use App\Shared\Payments\WebhookLog;
use App\Shared\Support\UsageCounterRegistry;
use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Writes a tenant's daily usage snapshot to the landlord (spec §6.4, §22.6).
 * Runs inside the tenant's context from the tenant daily maintenance job;
 * it is the only way cross-tenant usage reaches the landlord.
 *
 * Daily activity figures belong to the modules that own their tables: the
 * Orders module contributes orders_count and gross_sales, Accounting
 * contributes failed_postings. A figure no module contributes is recorded
 * as the column default, 0.
 */
final class TenantUsageReporter
{
    public const array FIGURES = ['orders_count', 'gross_sales', 'failed_postings'];

    /** @var array<string, Closure(CarbonImmutable, CarbonImmutable): (int|string)> */
    private array $figures = [];

    public function __construct(
        private readonly UsageCounterRegistry $counters,
        private readonly TenantSettingsService $settings,
    ) {}

    /**
     * @param  Closure(CarbonImmutable, CarbonImmutable): (int|string)  $compute  the day's value from its UTC bounds
     */
    public function contribute(string $figure, Closure $compute): void
    {
        if (! in_array($figure, self::FIGURES, true)) {
            throw new InvalidArgumentException("Unknown usage snapshot figure [{$figure}].");
        }

        $this->figures[$figure] = $compute;
    }

    /**
     * Upserts the snapshot of one local day (by default yesterday in the
     * tenant's timezone, the last complete day). Usage per limit key is the
     * value at the time of the run.
     */
    public function report(Tenant $tenant, ?CarbonImmutable $day = null): TenantUsageSnapshot
    {
        // tenants.timezone mirrors the tenant setting and is what the daily
        // dispatcher schedules by.
        $timezone = $tenant->timezone ?: 'UTC';
        $day = ($day ?? CarbonImmutable::now($timezone)->subDay())->setTimezone($timezone)->startOfDay();
        $from = $day->utc();
        $to = $day->endOfDay()->utc();

        $usage = [];

        foreach ($this->counters->keys() as $key) {
            try {
                $usage[$key] = $this->counters->count($key);
            } catch (Throwable $e) {
                // One broken counter must not lose the whole day's snapshot.
                report($e);
            }
        }

        $figures = [];

        foreach (self::FIGURES as $figure) {
            $figures[$figure] = isset($this->figures[$figure]) ? ($this->figures[$figure])($from, $to) : 0;
        }

        $webhookFailures = WebhookLog::on('tenant')
            ->whereNotNull('error')
            ->whereNull('processed_at')
            ->whereBetween('received_at', [$from, $to])
            ->count();

        /** @var TenantUsageSnapshot $snapshot */
        $snapshot = TenantUsageSnapshot::query()->updateOrCreate(
            ['tenant_id' => $tenant->getTenantKey(), 'date' => $day->toDateString()],
            [
                'usage' => $usage,
                'orders_count' => (int) $figures['orders_count'],
                'gross_sales' => bcadd((string) $figures['gross_sales'], '0', 4),
                'base_currency' => strtoupper((string) ($this->settings->get('default_currency') ?: $tenant->default_currency)),
                'webhook_failures' => $webhookFailures,
                'failed_postings' => (int) $figures['failed_postings'],
            ],
        );

        return $snapshot;
    }
}
