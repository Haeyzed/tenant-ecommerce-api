<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Plans\Enums\OverrideEffect;
use App\Modules\Plans\Models\TenantFeature;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-tenant grants, revokes and suspensions (spec §11.7, §11.13).
 */
final class TenantFeatureController extends Controller
{
    public function __construct(private readonly FeatureAccessService $features) {}

    public function index(Tenant $tenant): JsonResponse
    {
        return APIResponse::success(TenantFeature::query()->where('tenant_id', $tenant->id)->orderBy('feature_key')->get()
            ->map(fn (TenantFeature $f): array => $this->present($f)));
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'feature_key' => ['required', 'string', 'max:64'],
            'effect' => ['required', Rule::enum(OverrideEffect::class)],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'extra_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'extra_currency_code' => ['sometimes', 'nullable', 'string', 'size:3'],
            'billed' => ['sometimes', 'boolean'],
        ]);

        /** @var PlatformUser $user */
        $user = $request->user();
        $override = $this->features->setOverride(
            $tenant,
            $validated['feature_key'],
            OverrideEffect::from($validated['effect']),
            array_intersect_key($validated, array_flip(['starts_at', 'expires_at', 'reason', 'extra_amount', 'extra_currency_code', 'billed'])),
            $user,
        );

        return APIResponse::created($this->present($override), 'Override saved');
    }

    public function destroy(Request $request, Tenant $tenant, string $featureKey): JsonResponse
    {
        /** @var PlatformUser $user */
        $user = $request->user();
        $this->features->removeOverride($tenant, $featureKey, $user);

        return APIResponse::noContent('Override removed');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TenantFeature $override): array
    {
        return [
            'feature_key' => $override->feature_key,
            'effect' => $override->effect->value,
            'starts_at' => $override->starts_at?->toIso8601String(),
            'expires_at' => $override->expires_at?->toIso8601String(),
            'in_force' => $override->isInForce(),
            'reason' => $override->reason,
            'extra_amount' => $override->extra_amount !== null ? (string) $override->extra_amount : null,
            'extra_currency_code' => $override->extra_currency_code,
            'billed' => $override->billed,
            'overridden_by' => $override->overridden_by,
        ];
    }
}
