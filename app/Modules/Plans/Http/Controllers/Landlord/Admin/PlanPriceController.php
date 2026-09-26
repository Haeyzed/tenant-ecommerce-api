<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Plans\Http\Resources\PlanPriceResource;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Plans\Services\PlanService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlanPriceController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function index(Plan $plan): JsonResponse
    {
        return APIResponse::success(PlanPriceResource::collection($plan->prices()->orderByDesc('is_active')->orderBy('currency_code')->get()));
    }

    public function store(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate([
            'currency_code' => ['required', 'string', 'size:3'],
            'billing_interval' => ['required', 'in:monthly,yearly'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'trial_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'trial_requires_payment_method' => ['sometimes', 'boolean'],
        ]);

        $price = $this->plans->addPrice(
            $plan,
            $validated['currency_code'],
            $validated['billing_interval'],
            (string) $validated['amount'],
            isset($validated['trial_days']) ? (int) $validated['trial_days'] : null,
            (bool) ($validated['trial_requires_payment_method'] ?? false),
        );

        return APIResponse::created(new PlanPriceResource($price));
    }

    /**
     * Trial fields and is_active only; the amount is immutable (§11.6).
     */
    public function update(Request $request, Plan $plan, PlanPrice $price): JsonResponse
    {
        $request->validate([
            'trial_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'trial_requires_payment_method' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return APIResponse::success(new PlanPriceResource($this->plans->updatePrice($price, $request->all())), 'Price updated');
    }
}
