<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Portal;

use App\Modules\Affiliates\Http\Resources\AffiliateReferralResource;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ReferralController extends PortalController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(AffiliateReferral::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AffiliateReferral::query()
            ->with('tenant:id,name')
            ->where('affiliate_id', $this->affiliate($request)->id)
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('attributed_at')
            ->paginate($this->perPage($request));

        $collection = AffiliateReferralResource::collection($page);
        $collection->collection->each(static fn (AffiliateReferralResource $r) => $r->forPortal());

        return APIResponse::success($collection);
    }
}
