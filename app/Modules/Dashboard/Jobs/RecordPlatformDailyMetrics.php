<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Jobs;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Metrics\PlanMetrics;
use App\Modules\Billing\Metrics\SubscriptionMetrics;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Dashboard\Metrics\OperationsMetrics;
use App\Modules\Dashboard\Models\PlatformDailyMetric;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Metrics\TenantMetrics;
use App\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Records the previous platform day's stock metrics (spec §22.6, §72.2):
 * statuses change in place, so their history exists only here. Flows are
 * never stored; they are always computed from their source rows.
 *
 * Scheduled hourly; the first run after midnight in the platform timezone
 * records the day, later runs find it recorded and stop, and a missed run
 * is caught up by the next one. Upserts keep a retry idempotent.
 */
final class RecordPlatformDailyMetrics implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('landlord-default');
    }

    public function handle(
        PlatformSettingsService $settings,
        TenantMetrics $tenants,
        SubscriptionMetrics $subscriptions,
        PlanMetrics $plans,
        OperationsMetrics $operations,
        NotificationDispatchService $notifications,
    ): void {
        $timezone = $settings->timezone();
        $day = CarbonImmutable::now($timezone)->subDay()->startOfDay();

        if (PlatformDailyMetric::query()->where('date', $day->toDateString())->exists()) {
            return;
        }

        $rows = [];
        $add = static function (string $metric, array $values) use (&$rows, $day): void {
            foreach ($values as $dimension => $value) {
                $rows[] = [
                    'date' => $day->toDateString(),
                    'metric' => $metric,
                    'dimension' => (string) $dimension,
                    'value' => Money::normalize((string) $value),
                    'created_at' => now(),
                ];
            }
        };

        $add('tenants_by_status', $tenants->statusCounts());
        $add('subscriptions_by_status', $subscriptions->statusCounts());
        $add('paying_tenants_by_plan', array_column($plans->payingTenantsByPlan(), 'tenants', 'slug'));
        $add('affiliates_by_status', DB::connection('landlord')->table('affiliates')->groupBy('status')->selectRaw('status, COUNT(*) as aggregate')->pluck('aggregate', 'status')->all());
        $add('storage_mb_total', ['' => $operations->storageUsedMb()]);

        DB::connection('landlord')->table('platform_daily_metrics')->upsert($rows, ['date', 'metric', 'dimension'], ['value']);

        $this->alertFailedCharges($day, $notifications);
    }

    /**
     * One daily summary to billing admins when live charges failed that
     * day (platform.billing_payment_failed_alert).
     */
    private function alertFailedCharges(CarbonImmutable $day, NotificationDispatchService $notifications): void
    {
        $failed = DB::connection('landlord')->table('payment_transactions')
            ->where('type', PaymentTransaction::CHARGE)
            ->where('mode', 'live')
            ->where('status', PaymentTransaction::FAILED)
            ->whereBetween('created_at', [$day->utc(), $day->endOfDay()->utc()])
            ->groupBy('currency_code')
            ->selectRaw('currency_code, COUNT(*) as failures, SUM(amount) as total')
            ->get();

        if ($failed->isEmpty()) {
            return;
        }

        $recipients = PlatformUser::query()->withPlatformRole('billing-admin')->where('is_active', true)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $notifications->dispatch('platform.billing_payment_failed_alert', $recipients, [
            'date' => $day->toFormattedDateString(),
            'count' => (string) $failed->sum('failures'),
            'amount' => $failed->map(static fn ($row): string => Money::format(Money::normalize((string) $row->total), (string) $row->currency_code))->implode(', '),
        ]);
    }
}
