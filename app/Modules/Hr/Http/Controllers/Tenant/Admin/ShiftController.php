<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrShift;
use App\Modules\Hr\Services\HrShiftService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shift templates (spec §58.3a).
 */
final class ShiftController extends Controller
{
    public function __construct(
        private readonly HrShiftService $shifts,
        private readonly HrPresenter $presenter,
    ) {}

    /**
     * Query: active? (only active shifts).
     */
    public function index(Request $request): JsonResponse
    {
        return APIResponse::success($this->shifts->listShifts($request->boolean('active'))->map(fn (HrShift $s): array => $this->presenter->shift($s))->all());
    }

    /**
     * Body: name, start_time, end_time (HH:MM; an earlier end is the next day), break_minutes?,
     * late_grace_minutes?, early_leave_grace_minutes? (null = HR settings), color? (#RRGGBB).
     */
    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->shift($this->shifts->createShift($request->all())), 'Shift created');
    }

    /**
     * Body: any store field, is_active?
     */
    public function update(Request $request, HrShift $shift): JsonResponse
    {
        return APIResponse::success($this->presenter->shift($this->shifts->updateShift($shift, $request->all())), 'Shift updated');
    }

    public function destroy(HrShift $shift): JsonResponse
    {
        $this->shifts->deleteShift($shift);

        return APIResponse::noContent('Shift deleted');
    }
}
