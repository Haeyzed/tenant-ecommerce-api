<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Modules\Tenancy\Models\DatabaseServer;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The connection reports and their exports read (spec §6.6, §61.1): the
 * tenant server's read replica when one is configured, else the primary.
 * It is a separate connection on purpose: checkout, stock, payments and
 * accounting never read from a replica, which may lag.
 */
final class ReportConnection
{
    public const string NAME = 'tenant_read';

    /** The tenant and replica the connection is currently built for. */
    private static ?string $bound = null;

    public static function name(): string
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant || $tenant->database_server_id === null) {
            return 'tenant';
        }

        $readHost = DatabaseServer::query()->whereKey($tenant->database_server_id)->value('read_host');

        if ($readHost === null || $readHost === '') {
            return 'tenant';
        }

        // The primary connection already carries this tenant's database and credentials.
        $key = $tenant->getTenantKey().'@'.$readHost.'/'.config('database.connections.tenant.database');

        if (self::$bound !== $key) {
            config(['database.connections.'.self::NAME => [...(array) config('database.connections.tenant'), 'host' => $readHost, 'read' => null, 'write' => null, 'sticky' => false]]);
            DB::purge(self::NAME);
            self::$bound = $key;
        }

        return self::NAME;
    }
}
