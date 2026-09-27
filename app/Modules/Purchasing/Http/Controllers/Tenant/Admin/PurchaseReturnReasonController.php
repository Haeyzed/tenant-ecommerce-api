<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Http\PurchasingPresenter;
use App\Modules\Purchasing\Models\PurchaseReturnReason;
use App\Modules\Purchasing\Services\PurchaseReturnService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Purchase return reasons (spec §49.5, §49.7).
 */
final class PurchaseReturnReasonController extends Controller
{
    public function __construct(
        private readonly PurchaseReturnService $returns,
        private readonly PurchasingPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->returns->listReasons()->map(fn (PurchaseReturnReason $r): array => $this->presenter->reason($r))->values());
    }

    /**
     * Body: label.
     */
    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->reason($this->returns->saveReason($request->all())), 'Reason created');
    }

    /**
     * Body: label?, is_active?.
     */
    public function update(Request $request, PurchaseReturnReason $reason): JsonResponse
    {
        return APIResponse::success($this->presenter->reason($this->returns->saveReason($request->all(), $reason)), 'Reason updated');
    }
}
