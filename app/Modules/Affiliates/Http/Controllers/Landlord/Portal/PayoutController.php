<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Portal;

use App\Modules\Affiliates\Http\Resources\AffiliatePayoutResource;
use App\Modules\Affiliates\Models\AffiliatePayout;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PayoutController extends PortalController
{
    public function index(Request $request): JsonResponse
    {
        $page = AffiliatePayout::query()
            ->where('affiliate_id', $this->affiliate($request)->id)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $collection = AffiliatePayoutResource::collection($page);
        $collection->collection->each(static fn (AffiliatePayoutResource $r) => $r->forPortal());

        return APIResponse::success($collection);
    }

    /**
     * {payout} is the payout reference, scoped to the affiliate.
     */
    public function show(Request $request, string $payout): JsonResponse
    {
        $model = AffiliatePayout::query()
            ->with('commissions')
            ->where('affiliate_id', $this->affiliate($request)->id)
            ->where('reference', $payout)
            ->firstOrFail();

        return APIResponse::success((new AffiliatePayoutResource($model))->forPortal());
    }
}
