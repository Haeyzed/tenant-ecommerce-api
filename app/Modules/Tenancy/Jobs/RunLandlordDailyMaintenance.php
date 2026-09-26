<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Affiliates\Models\AffiliateClick;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Modules\Affiliates\Services\AffiliateAttributionService;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Plans\Models\TenantFeature;
use App\Modules\Plans\Models\TenantLimitOverride;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Models\TenantRegistration;
use App\Modules\Tenancy\Models\TenantUsageSnapshot;
use App\Modules\Tenancy\Services\TenantManagementService;
use App\Modules\Tenancy\Services\TenantRegistrationService;
use App\Shared\Activity\LandlordActivity;
use App\Shared\Idempotency\IdempotencyKey;
use App\Shared\Payments\WebhookLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Daily landlord housekeeping (spec §72.2). Each task runs in isolation: one
 * failing task is logged with its name and never blocks the others.
 */
final class RunLandlordDailyMaintenance implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 900];

    public int $timeout = 3000;

    public int $uniqueFor = 3600;

    private const int CHUNK = 1000;

    public function __construct()
    {
        $this->onQueue('landlord-default');
    }

    public function handle(TenantRegistrationService $registrations, TenantManagementService $tenants, PlatformSettingsService $settings, AffiliateAttributionService $attribution): void
    {
        $this->run('purges', static fn () => $tenants->schedulePurges());
        $this->run('registration_expiry', static fn () => $registrations->expireStaleRegistrations());
        $this->run('unpaid_tenants', static fn () => $registrations->closeUnpaidTenants());

        $this->run('expired_overrides', function (): void {
            $cutoff = now()->subDays(90);
            $this->chunkedDelete(TenantFeature::query()->whereNotNull('expires_at')->where('expires_at', '<', $cutoff));
            $this->chunkedDelete(TenantLimitOverride::query()->whereNotNull('expires_at')->where('expires_at', '<', $cutoff));
        });

        $this->run('webhook_logs', fn () => $this->chunkedDelete(WebhookLog::landlord()
            ->whereNotNull('processed_at')->whereNull('error')->where('processed_at', '<', now()->subDays(90))));

        $this->run('idempotency_keys', fn () => $this->chunkedDelete(IdempotencyKey::on('landlord')->where('expires_at', '<', now())));

        $this->run('affiliate_referral_expiry', static fn () => $attribution->expireStaleReferrals());

        // Clicks never linked to a registration or referral (§21A.3).
        $this->run('affiliate_clicks', fn () => $this->chunkedDelete(AffiliateClick::query()
            ->where('created_at', '<', now()->subMonths((int) config('affiliates.click_retention_months', 13)))
            ->whereNotIn('id', AffiliateReferral::query()->whereNotNull('affiliate_click_id')->select('affiliate_click_id'))
            ->whereNotIn('id', TenantRegistration::query()->whereNotNull('affiliate_click_id')->select('affiliate_click_id'))));

        $this->run('usage_snapshots', fn () => $this->chunkedDelete(TenantUsageSnapshot::query()->where('date', '<', now()->subDays(400)->toDateString())));

        $this->run('activity_log', fn () => $this->chunkedDelete(LandlordActivity::query()->where('created_at', '<', now()->subDays(365))));

        $this->run('unknown_refunds', static function (): void {
            PaymentTransaction::query()
                ->where('type', PaymentTransaction::REFUND)
                ->where('status', PaymentTransaction::PENDING)
                ->whereNull('provider_reference')
                ->where('created_at', '<', now()->subHour())
                ->chunkById(200, static function ($refunds): void {
                    foreach ($refunds as $refund) {
                        if (($refund->meta['needs_review'] ?? null) === null) {
                            // Never retried automatically (§14.9 step 4).
                            $refund->forceFill(['meta' => array_merge((array) $refund->meta, ['needs_review' => 'unknown_outcome'])])->save();
                            Log::warning('Platform refund with an unknown outcome needs review.', ['reference' => $refund->reference]);
                        }
                    }
                });
        });

        $this->run('custom_domains', static fn () => RecheckCustomDomains::dispatch(daily: true));

        $this->run('export_files', fn () => $this->pruneFiles('tenant-exports', now()->subHours(ExportTenant::LINK_HOURS + 24)->getTimestamp()));
        $this->run('purged_backups', fn () => $this->pruneFiles('purged-backups', now()->subDays((int) $settings->get('purged_backup_retention_days', 30))->getTimestamp()));
    }

    private function run(string $task, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::error('Landlord daily maintenance task failed.', ['task' => $task, 'error' => $e::class.': '.$e->getMessage()]);
            report($e);
        }
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function chunkedDelete($query): void
    {
        do {
            $ids = (clone $query)->limit(self::CHUNK)->pluck('id');

            if ($ids->isNotEmpty()) {
                $query->getModel()->newQuery()->whereKey($ids)->delete();
            }
        } while ($ids->count() === self::CHUNK);
    }

    private function pruneFiles(string $directory, int $olderThan): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files($directory) as $file) {
            if ($disk->lastModified($file) < $olderThan) {
                $disk->delete($file);
            }
        }
    }
}
