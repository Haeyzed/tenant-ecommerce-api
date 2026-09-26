<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Http\Resources\AffiliateReferralResource;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Modules\Affiliates\Services\AffiliateCommissionService;
use App\Modules\Affiliates\Services\AffiliateFraudService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AffiliateReferralController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(AffiliateReferral::STATUSES)],
            'requires_review' => ['sometimes', 'boolean'],
            'affiliate_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AffiliateReferral::query()
            ->with(['tenant:id,name', 'affiliate:id,name'])
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when(array_key_exists('requires_review', $filters), static fn ($q) => $q->where('requires_review', $request->boolean('requires_review')))
            ->when($filters['affiliate_id'] ?? null, static fn ($q, $v) => $q->where('affiliate_id', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success(AffiliateReferralResource::collection($page));
    }

    public function reject(Request $request, AffiliateReferral $referral, AffiliateCommissionService $commissions): JsonResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $referral = $commissions->rejectReferral($referral, $reason, $this->user($request));

        return APIResponse::success(new AffiliateReferralResource($referral->load(['tenant:id,name', 'affiliate:id,name'])), 'Referral rejected');
    }

    public function clearFlags(Request $request, AffiliateReferral $referral, AffiliateFraudService $fraud): JsonResponse
    {
        $note = (string) $request->validate(['note' => ['required', 'string', 'max:500']])['note'];
        $referral = $fraud->clearFlags($referral, $this->user($request), $note);

        return APIResponse::success(new AffiliateReferralResource($referral->load(['tenant:id,name', 'affiliate:id,name'])), 'Flags cleared');
    }

    private function user(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user();
    }
}
