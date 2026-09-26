<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantDomainService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The tenant's own domains (spec §7.5). A domain id of another tenant is
 * a 404, never a 403, so existence is not disclosed.
 */
final class DomainController extends Controller
{
    public function __construct(private readonly TenantDomainService $domains) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->domains->listDomains($this->tenant())->map(fn (Domain $d): array => $this->present($d))->values());
    }

    public function show(int $domain): JsonResponse
    {
        return APIResponse::success($this->present($this->own($domain), true));
    }

    public function store(Request $request): JsonResponse
    {
        $host = $request->validate(['domain' => ['required', 'string', 'max:253']])['domain'];

        return APIResponse::created($this->present($this->domains->addCustomDomain($this->tenant(), $host)));
    }

    /**
     * Runs a check now; one per minute per domain.
     */
    public function verify(int $domain): JsonResponse
    {
        $row = $this->own($domain);

        if (! Cache::store('landlord')->add('domain-verify:'.$row->id, true, 60)) {
            throw new ApiException('too_many_requests', 'Please wait a minute before checking again.', 429);
        }

        $row = match (true) {
            $row->verified_at === null => $this->domains->verify($row),
            $row->tls_status !== 'issued' => $this->domains->checkTls($row),
            default => $this->domains->recheck($row),
        };

        return APIResponse::success($this->present($row->refresh(), true));
    }

    public function makePrimary(int $domain): JsonResponse
    {
        return APIResponse::success($this->present($this->domains->makePrimary($this->own($domain))), 'Primary domain changed');
    }

    public function destroy(int $domain): JsonResponse
    {
        $this->domains->removeDomain($this->own($domain));

        return APIResponse::noContent('Domain removed');
    }

    private function own(int $id): Domain
    {
        return Domain::query()->where('tenant_id', $this->tenant()->getTenantKey())->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Domain $domain, bool $details = false): array
    {
        return array_filter([
            'id' => $domain->id,
            'domain' => $domain->domain,
            'type' => $domain->type,
            'is_primary' => $domain->is_primary,
            'status' => $domain->status->value,
            'verified_at' => $domain->verified_at?->toIso8601String(),
            'routing_type' => $domain->routing_type,
            'tls_status' => $domain->tls_status,
            'tls_expires_at' => $domain->tls_expires_at?->toIso8601String(),
            'failure_reason' => $domain->failure_reason,
            'dns_instructions' => $this->domains->dnsInstructions($domain),
            'dns_check_result' => $details ? $domain->dns_check_result : null,
        ], static fn (mixed $v, string $k): bool => $k !== 'dns_check_result' || $details, ARRAY_FILTER_USE_BOTH);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return tenant();
    }
}
