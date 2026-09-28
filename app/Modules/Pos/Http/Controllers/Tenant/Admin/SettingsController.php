<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Http\PosPresenter;
use App\Modules\Pos\Services\PosSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POS settings (spec §51.7).
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly PosSettingsService $settings,
        private readonly PosPresenter $presenter,
    ) {}

    public function show(): JsonResponse
    {
        return APIResponse::success($this->presenter->settings($this->settings->getSettings()));
    }

    public function update(Request $request): JsonResponse
    {
        return APIResponse::success($this->presenter->settings($this->settings->updateSettings($request->all())), 'POS settings updated');
    }
}
