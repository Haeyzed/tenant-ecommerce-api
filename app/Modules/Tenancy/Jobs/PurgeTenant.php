<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Plans\Models\TenantFeature;
use App\Modules\Plans\Models\TenantLimitOverride;
use App\Modules\Plans\Models\TenantModule;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\DatabaseServer;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUsageSnapshot;
use App\Modules\Tenancy\Support\TenantExporter;
use App\Shared\Activity\ActivityRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Irreversible removal of a closed tenant past purge_after (spec §6.7).
 * A final backup is taken first (kept for purged_backup_retention_days);
 * the landlord's financial, contractual and support records are kept.
 */
final class PurgeTenant implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $tenantId)
    {
        $this->onQueue('landlord-default');
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(TenantExporter $exporter): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        // Re-checked here: a restore may have happened since scheduling.
        if ($tenant === null || $tenant->status !== TenantStatus::Closed || $tenant->purge_after === null || $tenant->purge_after->isFuture()) {
            return;
        }

        if ($tenant->provisioned_at !== null) {
            $backup = $exporter->export($tenant, 'purged-backups');

            $database = $tenant->database()->getName();
            $manager = $tenant->database()->manager();

            if ($manager->databaseExists($database)) {
                $manager->deleteDatabase($tenant);
            }

            File::deleteDirectory(storage_path('tenants/'.$tenant->id));

            if ($tenant->database_server_id !== null) {
                DatabaseServer::query()->whereKey($tenant->database_server_id)->where('tenant_count', '>', 0)->decrement('tenant_count');
            }
        }

        DB::connection('landlord')->transaction(static function () use ($tenant): void {
            Domain::query()->where('tenant_id', $tenant->id)->get()->each->delete();
            TenantFeature::query()->where('tenant_id', $tenant->id)->delete();
            TenantLimitOverride::query()->where('tenant_id', $tenant->id)->delete();
            TenantModule::query()->where('tenant_id', $tenant->id)->delete();
            TenantUsageSnapshot::query()->where('tenant_id', $tenant->id)->delete();

            $tenant->forceFill(['status' => TenantStatus::Purged, 'purged_at' => now()])->save();
        });

        ActivityRecorder::landlord('tenants', 'Tenant purged', $tenant, ['backup' => $backup ?? null]);
    }
}
