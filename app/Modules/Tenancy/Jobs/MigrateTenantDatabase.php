<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantDatabasePreparer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Brings one tenant database to the current schema (spec §6.8). A failure
 * never blocks other tenants.
 */
final class MigrateTenantDatabase implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 1800;

    public function __construct(public readonly string $tenantId)
    {
        $this->onQueue('tenant-bulk');
    }

    public function handle(TenantDatabasePreparer $preparer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant !== null && $tenant->provisioned_at !== null && $tenant->purged_at === null) {
            $preparer->migrate($tenant);
        }
    }
}
