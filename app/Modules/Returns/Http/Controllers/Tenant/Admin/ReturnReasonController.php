<?php

declare(strict_types=1);

namespace App\Modules\Returns\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Returns\Http\ReturnPresenter;
use App\Modules\Returns\Models\ReturnReason;
use App\Modules\Returns\Services\ReturnReasonService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Return reasons (spec §41.7). DELETE deactivates.
 */
final class ReturnReasonController extends Controller
{
    public function __construct(
        private readonly ReturnReasonService $reasons,
        private readonly ReturnPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->reasons->listReasons()->map(fn (ReturnReason $r): array => $this->presenter->reason($r))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->reason($this->reasons->createReason($request->all())), 'Reason created');
    }

    public function update(Request $request, ReturnReason $reason): JsonResponse
    {
        return APIResponse::success($this->presenter->reason($this->reasons->updateReason($reason, $request->all())), 'Reason updated');
    }

    public function destroy(ReturnReason $reason): JsonResponse
    {
        return APIResponse::success($this->presenter->reason($this->reasons->deactivateReason($reason)), 'Reason deactivated');
    }
}
