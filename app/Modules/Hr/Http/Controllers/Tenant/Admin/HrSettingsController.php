<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Services\HrSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tenant-wide HR settings row (spec §58.3).
 */
final class HrSettingsController extends Controller
{
    public function __construct(
        private readonly HrSettingsService $settings,
        private readonly HrPresenter $presenter,
    ) {}

    public function show(): JsonResponse
    {
        return APIResponse::success($this->presenter->settings($this->settings->getDefaultSettings()));
    }

    /**
     * Body: expected_clock_in_time? (HH:MM), expected_clock_out_time?, late_grace_minutes?, early_leave_grace_minutes?
     */
    public function update(Request $request): JsonResponse
    {
        return APIResponse::success($this->presenter->settings($this->settings->setDefaultSettings($request->all())), 'HR settings saved');
    }
}
