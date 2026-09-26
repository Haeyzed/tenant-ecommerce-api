<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Http\Resources\AffiliatePayoutResource;
use App\Modules\Affiliates\Metrics\AffiliateMetricsService;
use App\Modules\Affiliates\Models\AffiliatePayout;
use App\Modules\Affiliates\Services\AffiliatePayoutService;
use App\Modules\Dashboard\Services\Landlord\PlatformDashboardService;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Requests\MetricsRangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Affiliate payouts (spec §21A.6). Paying is billing-admin work; the
 * affiliate-manager role only views (§75 rule 32).
 */
final class AffiliatePayoutController extends Controller
{
    public function __construct(private readonly AffiliatePayoutService $payouts) {}

    public function metrics(MetricsRangeRequest $request, PlatformDashboardService $dashboard, AffiliateMetricsService $metrics): JsonResponse
    {
        $result = $dashboard->contextualKpis('affiliate-payouts', $dashboard->range($request->rangeInput()), $metrics->payoutsStrip(...));

        return APIResponse::success($result['data'], meta: ['generated_at' => $result['generated_at'], 'cached' => $result['cached']]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(AffiliatePayout::STATUSES)],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'affiliate_id' => ['sometimes', 'integer'],
            'period_end' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AffiliatePayout::query()
            ->with('affiliate:id,name')
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['currency_code'] ?? null, static fn ($q, $v) => $q->where('currency_code', strtoupper($v)))
            ->when($filters['affiliate_id'] ?? null, static fn ($q, $v) => $q->where('affiliate_id', $v))
            ->when($filters['period_end'] ?? null, static fn ($q, $v) => $q->whereDate('period_end', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success(AffiliatePayoutResource::collection($page));
    }

    /**
     * The decrypted details snapshot is shown only to billing admins
     * (and super-admins), who make the transfer.
     */
    public function show(Request $request, AffiliatePayout $payout): JsonResponse
    {
        $resource = new AffiliatePayoutResource($payout->load(['affiliate:id,name', 'commissions']));
        $canPay = PlatformUser::query()->whereKey($this->user($request)->id)->withPlatformRole(['billing-admin', 'super-admin'])->exists();

        return APIResponse::success($canPay ? $resource->withPayoutDetails() : $resource);
    }

    public function generate(Request $request): JsonResponse
    {
        $periodEnd = $request->validate(['period_end' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']])['period_end'];
        $created = $this->payouts->generate(Carbon::parse($periodEnd), $this->user($request));

        return APIResponse::success(AffiliatePayoutResource::collection($created), $created->count().' payout(s) created');
    }

    public function markPaid(Request $request, AffiliatePayout $payout): JsonResponse
    {
        $reference = (string) $request->validate(['external_reference' => ['required', 'string', 'max:255']])['external_reference'];

        return APIResponse::success(new AffiliatePayoutResource($this->payouts->markPaid($payout, $reference, $this->user($request))), 'Payout marked paid');
    }

    public function markFailed(Request $request, AffiliatePayout $payout): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        return APIResponse::success(new AffiliatePayoutResource($this->payouts->markFailed($payout, $reason, $this->user($request))), 'Payout marked failed');
    }

    public function cancel(Request $request, AffiliatePayout $payout): JsonResponse
    {
        return APIResponse::success(new AffiliatePayoutResource($this->payouts->cancel($payout, $this->user($request))), 'Payout cancelled');
    }

    private function user(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user();
    }
}
