<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Dashboard\Services\Landlord\PlatformDashboardService;
use App\Modules\Tenancy\Http\Resources\TenantResource;
use App\Modules\Tenancy\Metrics\TenantMetrics;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantManagementService;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Requests\MetricsRangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tenant lifecycle administration (spec §7.4).
 */
final class TenantManagementController extends Controller
{
    public function __construct(private readonly TenantManagementService $tenants) {}

    /**
     * The list screen's KPI strip (spec §22.4).
     */
    public function metrics(MetricsRangeRequest $request, PlatformDashboardService $dashboard, TenantMetrics $metrics): JsonResponse
    {
        $result = $dashboard->contextualKpis('tenants', $dashboard->range($request->rangeInput()), $metrics->contextual(...));

        return APIResponse::success($result['data'], meta: ['generated_at' => $result['generated_at'], 'cached' => $result['cached']]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['awaiting_payment', 'provisioning', 'provisioning_failed', 'active', 'suspended', 'closed', 'purged'])],
            'plan' => ['sometimes', 'string', 'max:120'],
            'country' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success(TenantResource::collection($this->tenants->listTenants($filters)));
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $details = $this->tenants->getTenant($tenant);

        return APIResponse::success([
            'tenant' => new TenantResource($details['tenant']),
            'subscription' => $details['subscription'] === null ? null : new SubscriptionResource($details['subscription']),
            'modules' => $details['modules'],
            'usage' => $details['usage'],
        ]);
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        $reason = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:255']])['reason'] ?? null;

        return APIResponse::success(new TenantResource($this->tenants->suspendTenant($tenant, $reason, $this->user($request))), 'Tenant suspended');
    }

    public function reactivate(Request $request, Tenant $tenant): JsonResponse
    {
        return APIResponse::success(new TenantResource($this->tenants->reactivateTenant($tenant, $this->user($request))), 'Tenant reactivated');
    }

    public function close(Request $request, Tenant $tenant): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        return APIResponse::success(new TenantResource($this->tenants->closeTenant($tenant, $reason, $this->user($request))), 'Tenant closed');
    }

    public function restore(Request $request, Tenant $tenant): JsonResponse
    {
        return APIResponse::success(new TenantResource($this->tenants->restoreTenant($tenant, $this->user($request))), 'Tenant restored');
    }

    public function export(Request $request, Tenant $tenant): JsonResponse
    {
        $this->tenants->exportTenant($tenant, $this->user($request));

        return APIResponse::accepted(null, 'The export is being prepared; the link will be emailed to you');
    }

    private function user(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user();
    }
}
