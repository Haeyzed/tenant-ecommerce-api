<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Portal;

use App\Modules\Affiliates\Http\Resources\AffiliateCommissionResource;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class CommissionController extends PortalController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(AffiliateCommission::STATUSES)],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AffiliateCommission::query()
            ->where('affiliate_id', $this->affiliate($request)->id)
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['currency_code'] ?? null, static fn ($q, $v) => $q->where('currency_code', strtoupper($v)))
            ->when($filters['from'] ?? null, static fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, static fn ($q, $v) => $q->where('created_at', '<', Carbon::parse($v)->addDay()))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $collection = AffiliateCommissionResource::collection($page);
        $collection->collection->each(static fn (AffiliateCommissionResource $r) => $r->forPortal());

        return APIResponse::success($collection);
    }
}
