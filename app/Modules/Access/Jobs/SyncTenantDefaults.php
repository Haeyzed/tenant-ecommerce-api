<?php

declare(strict_types=1);

namespace App\Modules\Access\Jobs;

use App\Modules\Access\Services\TenantDefaultsSyncService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The additive defaults sync of one tenant (spec §12.6).
 */
final class SyncTenantDefaults implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 600;

    public function __construct(public readonly string $tenantId)
    {
        $this->onQueue('tenant-default');
    }

    public function handle(TenantDefaultsSyncService $defaults): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $tenant->provisioned_at === null || $tenant->purged_at !== null) {
            return;
        }

        $tenant->run(static fn () => $defaults->sync($tenant));
    }
}
