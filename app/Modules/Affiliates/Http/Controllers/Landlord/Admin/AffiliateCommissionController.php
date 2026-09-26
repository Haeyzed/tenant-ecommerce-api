<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Http\Resources\AffiliateCommissionResource;
use App\Modules\Affiliates\Metrics\AffiliateMetricsService;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Services\AffiliateCommissionService;
use App\Modules\Dashboard\Services\Landlord\PlatformDashboardService;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Requests\MetricsRangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AffiliateCommissionController extends Controller
{
    public function __construct(private readonly AffiliateCommissionService $commissions) {}

    public function metrics(MetricsRangeRequest $request, PlatformDashboardService $dashboard, AffiliateMetricsService $metrics): JsonResponse
    {
        $result = $dashboard->contextualKpis('affiliate-commissions', $dashboard->range($request->rangeInput()), $metrics->commissionsStrip(...));

        return APIResponse::success($result['data'], meta: ['generated_at' => $result['generated_at'], 'cached' => $result['cached']]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(AffiliateCommission::STATUSES)],
            'type' => ['sometimes', Rule::in([AffiliateCommission::COMMISSION, AffiliateCommission::CLAWBACK])],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'affiliate_id' => ['sometimes', 'integer'],
            'eligible_only' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AffiliateCommission::query()
            ->with('affiliate:id,name')
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, static fn ($q, $v) => $q->where('type', $v))
            ->when($filters['currency_code'] ?? null, static fn ($q, $v) => $q->where('currency_code', strtoupper($v)))
            ->when($filters['affiliate_id'] ?? null, static fn ($q, $v) => $q->where('affiliate_id', $v))
            ->when($request->boolean('eligible_only'), static fn ($q) => $q->eligible())
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success(AffiliateCommissionResource::collection($page));
    }

    public function show(AffiliateCommission $commission): JsonResponse
    {
        return APIResponse::success(new AffiliateCommissionResource($commission->load('affiliate:id,name')));
    }

    public function approve(Request $request, AffiliateCommission $commission): JsonResponse
    {
        $note = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:500']])['note'] ?? null;

        return APIResponse::success(new AffiliateCommissionResource($this->commissions->approve($commission, $this->user($request), $note)), 'Commission approved');
    }

    public function reject(Request $request, AffiliateCommission $commission): JsonResponse
    {
        return APIResponse::success(new AffiliateCommissionResource($this->commissions->reject($commission, $this->reason($request), $this->user($request))), 'Commission rejected');
    }

    public function reverse(Request $request, AffiliateCommission $commission): JsonResponse
    {
        return APIResponse::success(new AffiliateCommissionResource($this->commissions->reverse($commission, $this->reason($request), $this->user($request))), 'Commission reversed');
    }

    private function reason(Request $request): string
    {
        return (string) $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
    }

    private function user(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user();
    }
}
