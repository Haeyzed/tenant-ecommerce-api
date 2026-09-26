<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Http\Resources\AffiliateResource;
use App\Modules\Affiliates\Metrics\AffiliateMetricsService;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Modules\Affiliates\Services\AffiliateCommissionService;
use App\Modules\Affiliates\Services\AffiliateFraudService;
use App\Modules\Affiliates\Services\AffiliateService;
use App\Modules\Dashboard\Services\Landlord\PlatformDashboardService;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Requests\MetricsRangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Affiliate administration (spec §21A.10).
 */
final class AffiliateController extends Controller
{
    public function __construct(private readonly AffiliateService $affiliates) {}

    public function metrics(MetricsRangeRequest $request, PlatformDashboardService $dashboard, AffiliateMetricsService $metrics): JsonResponse
    {
        $result = $dashboard->contextualKpis('affiliates', $dashboard->range($request->rangeInput()), $metrics->affiliatesStrip(...));

        return APIResponse::success($result['data'], meta: ['generated_at' => $result['generated_at'], 'cached' => $result['cached']]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(Affiliate::STATUSES)],
            'search' => ['sometimes', 'string', 'max:100'],
            'has_flags' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Affiliate::query()
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['search'] ?? null, static fn ($q, $v) => $q->where(static fn ($q) => $q
                ->where('name', 'like', '%'.$v.'%')
                ->orWhere('email', 'like', '%'.$v.'%')
                ->orWhere('referral_code', strtoupper($v))))
            ->when($request->boolean('has_flags'), static fn ($q) => $q->whereHas('referrals', static fn ($r) => $r
                ->where('requires_review', true)->whereIn('status', [AffiliateReferral::REGISTERED, AffiliateReferral::CONVERTED])))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success(AffiliateResource::collection($page));
    }

    public function show(Affiliate $affiliate, AffiliateCommissionService $commissions, AffiliateFraudService $fraud): JsonResponse
    {
        $referrals = AffiliateReferral::query()->where('affiliate_id', $affiliate->id)
            ->groupBy('status')->selectRaw('status, COUNT(*) as aggregate')->pluck('aggregate', 'status')
            ->map(static fn ($v): int => (int) $v)->all();

        return APIResponse::success([
            'affiliate' => new AffiliateResource($affiliate),
            'referrals' => array_merge(array_fill_keys(AffiliateReferral::STATUSES, 0), $referrals),
            'balances' => $commissions->balances($affiliate),
            'rapid_refund' => $fraud->hasRapidRefunds($affiliate->id),
        ]);
    }

    public function approve(Request $request, Affiliate $affiliate): JsonResponse
    {
        $code = $request->validate(['referral_code' => ['sometimes', 'nullable', 'string', 'max:20']])['referral_code'] ?? null;

        return APIResponse::success(new AffiliateResource($this->affiliates->approve($affiliate, $code, $this->user($request))), 'Affiliate approved');
    }

    public function reject(Request $request, Affiliate $affiliate): JsonResponse
    {
        return APIResponse::success(new AffiliateResource($this->affiliates->reject($affiliate, $this->reason($request), $this->user($request))), 'Application rejected');
    }

    public function suspend(Request $request, Affiliate $affiliate): JsonResponse
    {
        return APIResponse::success(new AffiliateResource($this->affiliates->suspend($affiliate, $this->reason($request), $this->user($request))), 'Affiliate suspended');
    }

    public function reinstate(Request $request, Affiliate $affiliate): JsonResponse
    {
        return APIResponse::success(new AffiliateResource($this->affiliates->reinstate($affiliate, $this->user($request))), 'Affiliate reinstated');
    }

    public function close(Request $request, Affiliate $affiliate): JsonResponse
    {
        return APIResponse::success(new AffiliateResource($this->affiliates->close($affiliate, $this->reason($request), $this->user($request))), 'Affiliate closed');
    }

    public function commissionRate(Request $request, Affiliate $affiliate): JsonResponse
    {
        $validated = $request->validate([
            'commission_rate' => ['present', 'nullable', 'numeric', 'min:0', 'max:100'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $rate = $validated['commission_rate'] === null ? null : (string) $validated['commission_rate'];

        return APIResponse::success(new AffiliateResource($this->affiliates->setCommissionRate($affiliate, $rate, $validated['reason'], $this->user($request))), 'Commission rate updated');
    }

    public function referralCode(Request $request, Affiliate $affiliate): JsonResponse
    {
        $code = $request->validate(['referral_code' => ['required', 'string', 'max:20']])['referral_code'];

        return APIResponse::success(new AffiliateResource($this->affiliates->changeReferralCode($affiliate, $code, $this->user($request))), 'Referral code changed');
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
