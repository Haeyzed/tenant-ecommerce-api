<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Catalog\Services\ProductViewService;
use App\Modules\Cms\Services\ContactSubmissionService;
use App\Modules\Cms\Support\CmsSitemapSource;
use App\Modules\Exports\Services\DataExportService;
use App\Modules\Inventory\Support\StockAlerts;
use App\Modules\Seo\Support\SitemapBuilder;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantUsageReporter;
use App\Shared\Idempotency\IdempotencyKey;
use App\Shared\Payments\WebhookLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * One tenant's daily work (spec §72.2). Every task runs in isolation, so one
 * failure never blocks the others; tasks of disabled features are skipped.
 * Module tasks join this list as their modules are built.
 */
final class RunTenantDailyMaintenance implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 1800];

    public int $timeout = 1800;

    public int $uniqueFor = 7200;

    private const int CHUNK = 1000;

    public function __construct(public readonly string $tenantId)
    {
        $this->onQueue('tenant-default');
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(DataExportService $exports, TenantUsageReporter $usage): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        // Purged or never-provisioned tenants have no database (§72.1).
        if ($tenant === null || $tenant->provisioned_at === null
            || ! in_array($tenant->status, [TenantStatus::Active, TenantStatus::Suspended, TenantStatus::Closed], true)) {
            return;
        }

        $tenant->run(function () use ($exports, $usage, $tenant): void {
            $this->task('usage_snapshot', static fn () => $usage->report($tenant));
            $this->task('contact_submissions', static fn () => app(ContactSubmissionService::class)->purgeExpired());
            $this->task('product_views', static fn () => app(ProductViewService::class)->purgeOld());
            $this->task('expiring_products', static fn () => app(StockAlerts::class)->checkExpiringProducts());

            // Rebuild the sitemap only when content changed since the last build (§30.2).
            $this->task('sitemap', static function () use ($tenant): void {
                $disk = Storage::disk('local');
                $builtAt = $disk->exists(SitemapBuilder::TENANT_FILE) ? $disk->lastModified(SitemapBuilder::TENANT_FILE) : null;

                if (CmsSitemapSource::changedSince($builtAt)) {
                    app(SitemapBuilder::class)->buildForTenant($tenant);
                }
            });
            $this->task('export_files', static fn () => $exports->expireFiles());

            $this->task('idempotency_keys', fn () => $this->chunkedDelete('idempotency_keys', IdempotencyKey::query()->where('expires_at', '<', now())->toBase()));
            $this->task('webhook_logs', fn () => $this->chunkedDelete('webhook_logs', WebhookLog::on('tenant')
                ->whereNotNull('processed_at')->whereNull('error')->where('processed_at', '<', now()->subDays(90))->toBase()));
            $this->task('activity_log', fn () => $this->chunkedDelete('activity_log', Activity::query()->where('created_at', '<', now()->subDays(365))->toBase()));
        });
    }

    private function task(string $name, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::error('Tenant daily maintenance task failed.', ['tenant_id' => $this->tenantId, 'task' => $name, 'error' => $e::class.': '.$e->getMessage()]);
            report($e);
        }
    }

    private function chunkedDelete(string $table, Builder $query): void
    {
        do {
            $ids = (clone $query)->limit(self::CHUNK)->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::connection('tenant')->table($table)->whereIn('id', $ids)->delete();
            }
        } while ($ids->count() === self::CHUNK);
    }
}
