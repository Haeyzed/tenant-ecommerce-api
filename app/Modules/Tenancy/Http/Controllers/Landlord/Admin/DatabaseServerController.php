<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\DatabaseServer;
use App\Modules\Tenancy\Support\TenantDatabaseConfig;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Database servers for tenant placement (spec §6.6, §7.4). The password is
 * write-only.
 */
final class DatabaseServerController extends Controller
{
    public function index(): JsonResponse
    {
        return APIResponse::success(DatabaseServer::query()->orderBy('name')->get()->map(fn (DatabaseServer $s): array => $this->present($s)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('landlord.database_servers', 'name')],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['sometimes', 'integer', 'between:1,65535'],
            'read_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:1024'],
            'max_tenants' => ['required', 'integer', 'min:1'],
            'is_accepting_tenants' => ['sometimes', 'boolean'],
        ]);

        /** @var DatabaseServer $server */
        $server = DatabaseServer::query()->create($validated);
        ActivityRecorder::landlord('database_servers', "Database server {$server->name} registered", $server);

        return APIResponse::created($this->present($server));
    }

    /**
     * Capacity and is_accepting_tenants (§7.4).
     */
    public function update(Request $request, DatabaseServer $server): JsonResponse
    {
        $validated = $request->validate([
            'max_tenants' => ['sometimes', 'integer', 'min:1'],
            'is_accepting_tenants' => ['sometimes', 'boolean'],
        ]);

        $server->fill($validated)->save();
        TenantDatabaseConfig::flushServerCache();
        ActivityRecorder::landlord('database_servers', "Database server {$server->name} updated", $server, $validated);

        return APIResponse::success($this->present($server), 'Database server updated');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseServer $server): array
    {
        return [
            'id' => $server->id,
            'name' => $server->name,
            'host' => $server->host,
            'port' => $server->port,
            'read_host' => $server->read_host,
            'username' => $server->username,
            'max_tenants' => $server->max_tenants,
            'tenant_count' => $server->tenant_count,
            'utilisation' => round($server->utilisation() * 100, 1),
            'is_accepting_tenants' => $server->is_accepting_tenants,
        ];
    }
}
