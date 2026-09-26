<?php

declare(strict_types=1);

namespace App\Modules\Access\Jobs;

use App\Modules\Access\Services\TenantDefaultsSyncService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;

/**
 * Deploy-time fan-out (spec §12.6): one SyncTenantDefaults per provisioned
 * tenant whose permissions_version is behind.
 */
final class SyncTenantDefaultsForAllTenants implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public readonly bool $force = false)
    {
        $this->onQueue('landlord-default');
    }

    public function handle(TenantDefaultsSyncService $defaults): void
    {
        $version = $defaults->version();

        Tenant::query()
            ->whereNotNull('provisioned_at')
            ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Suspended->value, TenantStatus::Closed->value])
            ->when(! $this->force, static fn ($q) => $q->where(static fn ($q) => $q->whereNull('permissions_version')->orWhere('permissions_version', '!=', $version)))
            ->select('id')
            ->chunkById(500, static function ($tenants): void {
                Bus::batch($tenants->map(static fn (Tenant $t): SyncTenantDefaults => new SyncTenantDefaults((string) $t->id))->all())
                    ->name('tenant-defaults-sync')
                    ->allowFailures()
                    ->onQueue('tenant-default')
                    ->dispatch();
            });
    }
}
