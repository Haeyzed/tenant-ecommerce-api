<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Plans\Models\TenantLimitOverride;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantLimitOverrideController extends Controller
{
    public function __construct(private readonly PlanLimitService $limits) {}

    public function index(Tenant $tenant): JsonResponse
    {
        return APIResponse::success(TenantLimitOverride::query()->where('tenant_id', $tenant->id)->orderBy('limit_key')->get()
            ->map(fn (TenantLimitOverride $o): array => $this->present($o)));
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'limit_key' => ['required', 'string', 'max:64'],
            'limit_value' => ['present', 'nullable', 'integer', 'min:0'],
            'extra_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'extra_currency_code' => ['sometimes', 'nullable', 'string', 'size:3'],
            'billed' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        /** @var PlatformUser $user */
        $user = $request->user();
        $override = $this->limits->setLimitOverride(
            $tenant,
            $validated['limit_key'],
            $validated['limit_value'] === null ? null : (int) $validated['limit_value'],
            array_intersect_key($validated, array_flip(['extra_amount', 'extra_currency_code', 'billed', 'expires_at', 'reason'])),
            $user,
        );

        return APIResponse::success($this->present($override), 'Limit override saved');
    }

    public function destroy(Request $request, Tenant $tenant, string $limitKey): JsonResponse
    {
        /** @var PlatformUser $user */
        $user = $request->user();
        $this->limits->removeLimitOverride($tenant, $limitKey, $user);

        return APIResponse::noContent('Limit override removed');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TenantLimitOverride $override): array
    {
        return [
            'limit_key' => $override->limit_key,
            'limit_value' => $override->limit_value,
            'extra_amount' => $override->extra_amount !== null ? (string) $override->extra_amount : null,
            'extra_currency_code' => $override->extra_currency_code,
            'billed' => $override->billed,
            'is_active' => $override->is_active,
            'in_force' => $override->isInForce(),
            'expires_at' => $override->expires_at?->toIso8601String(),
            'reason' => $override->reason,
        ];
    }
}
