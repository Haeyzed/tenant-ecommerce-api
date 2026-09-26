<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Metrics\SubscriptionMetrics;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Dashboard\Services\Landlord\PlatformDashboardService;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Requests\MetricsRangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * The list screen's KPI strip (spec §22.4).
     */
    public function metrics(MetricsRangeRequest $request, PlatformDashboardService $dashboard, SubscriptionMetrics $metrics): JsonResponse
    {
        $result = $dashboard->contextualKpis('subscriptions', $dashboard->range($request->rangeInput()), $metrics->contextual(...));

        return APIResponse::success($result['data'], meta: ['generated_at' => $result['generated_at'], 'cached' => $result['cached']]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['incomplete', 'trialing', 'active', 'past_due', 'cancelled'])],
            'mode' => ['sometimes', 'in:test,live'],
            'tenant' => ['sometimes', 'string', 'max:255'],
            'plan_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Subscription::query()
            ->with('plan')
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['mode'] ?? null, static fn ($q, $v) => $q->where('gateway_mode', $v))
            ->when($filters['tenant'] ?? null, static fn ($q, $v) => $q->where('tenant_id', $v))
            ->when($filters['plan_id'] ?? null, static fn ($q, $v) => $q->where('plan_id', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success(SubscriptionResource::collection($page));
    }

    public function show(Subscription $subscription): JsonResponse
    {
        return APIResponse::success(new SubscriptionResource($subscription->load('plan')));
    }

    public function extendTrial(Request $request, Subscription $subscription): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var PlatformUser $user */
        $user = $request->user();
        $subscription = $this->subscriptions->extendTrial($subscription, (int) $validated['days'], $validated['reason'], $user);

        return APIResponse::success(new SubscriptionResource($subscription->load('plan')), 'Trial extended');
    }
}
