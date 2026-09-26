<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Hourly (spec §72.2): one RunTenantDailyMaintenance for every active,
 * suspended or closed tenant whose local time is now 01:00–01:59, each
 * delayed by up to 50 minutes so one timezone's load spreads over its hour.
 */
final class DispatchTenantDailyMaintenance implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [120];

    public int $timeout = 900;

    public int $uniqueFor = 3000;

    private const int LOCAL_HOUR = 1;

    public function __construct()
    {
        $this->onQueue('landlord-default');
    }

    public function handle(): void
    {
        $timezones = $this->timezonesAtLocalHour(self::LOCAL_HOUR);

        if ($timezones === []) {
            return;
        }

        Tenant::query()
            ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Suspended->value, TenantStatus::Closed->value])
            ->whereNotNull('provisioned_at')
            ->whereIn('timezone', $timezones)
            ->select(['id'])
            ->chunkById(1000, static function ($tenants): void {
                foreach ($tenants as $tenant) {
                    RunTenantDailyMaintenance::dispatch((string) $tenant->id)->delay(now()->addSeconds(random_int(0, 3000)));
                }
            });
    }

    /**
     * The IANA zones whose local hour is now $hour.
     *
     * @return list<string>
     */
    private function timezonesAtLocalHour(int $hour): array
    {
        $now = now();

        return array_values(array_filter(
            DateTimeZone::listIdentifiers(),
            static fn (string $zone): bool => (int) $now->copy()->setTimezone($zone)->format('G') === $hour,
        ));
    }
}
