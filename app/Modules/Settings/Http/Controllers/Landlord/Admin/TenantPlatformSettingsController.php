<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\TenantPlatformSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-owned settings of one tenant, e.g. its commission rate (§13.3).
 */
final class TenantPlatformSettingsController extends Controller
{
    public function __construct(private readonly TenantPlatformSettingsService $settings) {}

    public function show(Tenant $tenant): JsonResponse
    {
        return APIResponse::success($this->present($tenant));
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $this->settings->update($tenant, (array) $request->input('values', $request->all()));

        return APIResponse::success($this->present($tenant), 'Tenant settings updated');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Tenant $tenant): array
    {
        return [
            'values' => $this->settings->values($tenant),
            'effective_commission_rate' => $this->settings->getEffectiveCommissionRate($tenant),
        ];
    }
}
