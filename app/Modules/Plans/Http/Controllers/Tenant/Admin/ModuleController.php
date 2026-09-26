<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Plans\Services\ModuleActivationService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tenant's module screen (spec §11.13). Always available.
 */
final class ModuleController extends Controller
{
    public function __construct(
        private readonly FeatureAccessService $features,
        private readonly ModuleActivationService $activation,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->features->listEffectiveModules($this->tenant()));
    }

    public function enable(Request $request, string $moduleKey): JsonResponse
    {
        $this->activation->enable($this->tenant(), $moduleKey, $this->user($request));

        return APIResponse::success($this->module($moduleKey), 'Module enabled');
    }

    public function disable(Request $request, string $moduleKey): JsonResponse
    {
        $this->activation->disable($this->tenant(), $moduleKey, $this->user($request));

        return APIResponse::success($this->module($moduleKey), 'Module disabled');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function module(string $key): ?array
    {
        return collect($this->features->listEffectiveModules($this->tenant()))->firstWhere('key', $key);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return tenant();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
