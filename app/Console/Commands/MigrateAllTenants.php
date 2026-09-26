<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Jobs\MigrateTenantDatabase;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantDatabasePreparer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Queues migrations for every tenant behind the current schema (spec §6.8).
 * A deployment never migrates tenants inside the deploy command itself.
 */
#[Signature('tenants:migrate-all {--dry-run : List the tenants that are behind without queueing}')]
#[Description('Queue tenant migrations for every tenant whose schema_version is behind')]
final class MigrateAllTenants extends Command
{
    public function handle(TenantDatabasePreparer $preparer): int
    {
        $latest = $preparer->latestMigration();
        $query = Tenant::query()
            ->whereNotNull('provisioned_at')
            ->whereNull('purged_at')
            ->where(static fn ($q) => $q->whereNull('schema_version')->orWhere('schema_version', '!=', $latest));

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} tenants are behind {$latest}.");

            return self::SUCCESS;
        }

        $query->select('id')->chunkById(500, static function ($tenants): void {
            Bus::batch($tenants->map(static fn (Tenant $t): MigrateTenantDatabase => new MigrateTenantDatabase((string) $t->id))->all())
                ->name('tenant-migrations')
                ->allowFailures()
                ->onQueue('tenant-bulk')
                ->dispatch();
        });

        $this->info("Queued migrations for {$count} tenants to {$latest}.");

        return self::SUCCESS;
    }
}
