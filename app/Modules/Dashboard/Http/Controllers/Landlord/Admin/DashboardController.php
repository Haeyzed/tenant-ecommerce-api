<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Dashboard\Services\Landlord\PlatformDashboardService;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Requests\MetricsRangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The landlord dashboard (spec §22.3, §22.8): one request per section.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly PlatformDashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        return APIResponse::success(['sections' => $this->dashboard->sections($this->user($request))]);
    }

    public function show(MetricsRangeRequest $request, string $section): JsonResponse
    {
        $result = $this->dashboard->cachedSection($section, $this->dashboard->range($request->rangeInput()), $this->user($request));

        return APIResponse::success($result['data'], meta: ['generated_at' => $result['generated_at'], 'cached' => $result['cached']]);
    }

    private function user(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user();
    }
}
