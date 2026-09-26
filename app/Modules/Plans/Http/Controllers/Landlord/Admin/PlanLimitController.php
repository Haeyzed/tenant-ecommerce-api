<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanLimit;
use App\Modules\Plans\Services\PlanService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlanLimitController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function index(Plan $plan): JsonResponse
    {
        return APIResponse::success($this->limits($plan));
    }

    /**
     * Upserts by limit_key; null means unlimited where allowed (§11.8).
     */
    public function store(Request $request, Plan $plan): JsonResponse
    {
        $validated = $request->validate([
            'limit_key' => ['required', 'string', 'max:64'],
            'limit_value' => ['present', 'nullable', 'integer', 'min:0'],
        ]);

        $this->plans->setPlanLimit($plan, $validated['limit_key'], $validated['limit_value'] === null ? null : (int) $validated['limit_value']);

        return APIResponse::success($this->limits($plan), 'Limit saved');
    }

    /**
     * @return list<array{limit_key: string, limit_value: int|null, kind: string, unlimited_allowed: bool, label: string}>
     */
    private function limits(Plan $plan): array
    {
        $values = $plan->limits()->get()->keyBy('limit_key');
        $result = [];

        foreach ((array) config('limits') as $key => $definition) {
            /** @var PlanLimit|null $row */
            $row = $values->get($key);

            $result[] = [
                'limit_key' => $key,
                'limit_value' => $row?->limit_value,
                'configured' => $row !== null,
                'kind' => (string) $definition['kind'],
                'unlimited_allowed' => (bool) $definition['unlimited_allowed'],
                'label' => (string) $definition['label'],
            ];
        }

        return $result;
    }
}
