<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Services\PlanService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlanFeatureController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function index(Plan $plan): JsonResponse
    {
        return APIResponse::success($plan->features()->orderBy('feature_key')->pluck('feature_key')->values());
    }

    public function store(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate(['feature_key' => ['required', 'string', 'max:64']]);
        $this->plans->attachFeatureToPlan($plan, $validated['feature_key']);

        return APIResponse::created($plan->features()->orderBy('feature_key')->pluck('feature_key')->values(), 'Feature attached');
    }

    public function destroy(Plan $plan, string $featureKey): JsonResponse
    {
        $this->plans->detachFeatureFromPlan($plan, $featureKey);

        return APIResponse::noContent('Feature detached');
    }
}
