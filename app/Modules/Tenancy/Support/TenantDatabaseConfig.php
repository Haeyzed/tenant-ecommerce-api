<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\DatabaseServer;
use App\Modules\Tenancy\Models\Tenant;
use Stancl\Tenancy\DatabaseConfig;

/**
 * Builds the tenant connection from the "tenant_template" connection and the
 * tenant's database server row (spec §6.6). Credentials never live on the
 * tenant row; they are read from the encrypted server row when needed.
 */
final class TenantDatabaseConfig extends DatabaseConfig
{
    /** @var array<int, DatabaseServer> */
    private static array $servers = [];

    /**
     * @return array<string, mixed>
     */
    public function tenantConfig(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->tenant;

        if ($tenant->database_server_id === null) {
            return [];
        }

        $server = self::$servers[$tenant->database_server_id]
            ??= DatabaseServer::query()->findOrFail($tenant->database_server_id);

        return [
            'host' => $server->host,
            'port' => $server->port,
            'username' => $server->username,
            'password' => $server->password,
        ];
    }

    /**
     * Drop the per-process server memo (after a server row changes).
     */
    public static function flushServerCache(): void
    {
        self::$servers = [];
    }
}
