<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\OnboardingService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * The onboarding checklist (spec §9.6), visible to any staff user.
 */
final class OnboardingController extends Controller
{
    public function show(OnboardingService $onboarding): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return APIResponse::success($onboarding->checklist($tenant));
    }
}
