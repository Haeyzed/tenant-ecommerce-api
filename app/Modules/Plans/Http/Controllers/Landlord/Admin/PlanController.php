<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Plans\Http\Resources\PlanResource;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Services\PlanService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PlanController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function index(): JsonResponse
    {
        return APIResponse::success(PlanResource::collection(
            Plan::query()->with(['prices', 'features', 'limits'])->orderBy('sort_order')->orderBy('id')->get(),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $plan = $this->plans->createPlan($request->validate($this->rules()));

        return APIResponse::created(new PlanResource($plan->load(['prices', 'features', 'limits'])));
    }

    public function show(Plan $plan): JsonResponse
    {
        return APIResponse::success(new PlanResource($plan->load(['prices', 'features', 'limits'])));
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $plan = $this->plans->updatePlan($plan, $request->validate($this->rules($plan)));

        return APIResponse::success(new PlanResource($plan->load(['prices', 'features', 'limits'])), 'Plan updated');
    }

    public function deactivate(Plan $plan): JsonResponse
    {
        $this->plans->deactivatePlan($plan);

        return APIResponse::success(new PlanResource($plan->refresh()->load(['prices', 'features', 'limits'])), 'Plan deactivated');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(?Plan $plan = null): array
    {
        $required = $plan === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:120', 'alpha_dash:ascii', Rule::unique('landlord.plans', 'slug')->ignore($plan?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'is_recommended' => ['sometimes', 'boolean'],
            'marketing_badge' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
