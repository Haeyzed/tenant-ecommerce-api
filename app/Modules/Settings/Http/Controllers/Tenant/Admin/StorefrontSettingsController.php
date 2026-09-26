<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\StorefrontSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Storefront presentation settings (spec §13.5).
 */
final class StorefrontSettingsController extends Controller
{
    public function __construct(private readonly StorefrontSettingsService $settings) {}

    public function show(): JsonResponse
    {
        return APIResponse::success($this->settings->all());
    }

    public function update(Request $request): JsonResponse
    {
        $this->settings->update((array) $request->input('values', $request->all()));

        return APIResponse::success($this->settings->all(), 'Storefront settings updated');
    }
}
