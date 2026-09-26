<?php

declare(strict_types=1);

namespace App\Modules\Plans\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Services\PlanService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public pricing page (spec §11.13): active public plans only.
 */
final class PlanController extends Controller
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly PlatformSettingsService $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $currency = $request->validate(['currency' => ['sometimes', 'string', 'size:3']])['currency'] ?? null;

        return APIResponse::success($this->plans->listPublicPlans($currency));
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $currency = $request->validate(['currency' => ['sometimes', 'string', 'size:3']])['currency']
            ?? (string) $this->settings->get('default_currency', 'USD');

        $plan = Plan::query()->where('slug', $slug)->where('is_active', true)->where('is_public', true)->firstOrFail();

        return APIResponse::success($this->plans->presentPublic($plan, strtoupper($currency)));
    }
}
