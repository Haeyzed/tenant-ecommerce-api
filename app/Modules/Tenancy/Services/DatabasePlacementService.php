<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\DatabaseServer;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;

/**
 * Places a tenant on a database server and creates its database (spec
 * §6.6, §9.4 step 1). Idempotent: a placed tenant keeps its server, and an
 * existing database is reused.
 */
final class DatabasePlacementService
{
    public function place(Tenant $tenant): void
    {
        if ($tenant->database_server_id !== null) {
            return;
        }

        // A single-server installation registers no rows and uses the
        // tenant_template connection as is.
        if (! DatabaseServer::query()->exists()) {
            return;
        }

        DB::connection('landlord')->transaction(function () use ($tenant): void {
            /** @var DatabaseServer|null $server */
            $server = DatabaseServer::query()
                ->where('is_accepting_tenants', true)
                ->whereColumn('tenant_count', '<', 'max_tenants')
                ->lockForUpdate()
                ->get()
                ->sortBy(static fn (DatabaseServer $s): float => $s->utilisation())
                ->first();

            if ($server === null) {
                throw ApiException::conflict('no_database_capacity', 'No database server is accepting new tenants.');
            }

            $server->increment('tenant_count');
            $tenant->forceFill(['database_server_id' => $server->id])->save();
        });
    }

    public function createDatabase(Tenant $tenant): void
    {
        $config = $tenant->database();
        $name = $config->getName();

        /** @var TenantDatabaseManager $manager */
        $manager = $config->manager();

        if (! $manager->databaseExists($name)) {
            $manager->createDatabase($tenant);
        }
    }
}
