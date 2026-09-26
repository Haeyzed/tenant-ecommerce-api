<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Portal;

use App\Modules\Affiliates\Metrics\AffiliateMetricsService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Requests\MetricsRangeRequest;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsCache;
use Illuminate\Http\JsonResponse;

/**
 * The affiliate's own KPIs (spec §21A.8), cached five minutes per
 * affiliate and parameter set.
 */
final class DashboardController extends PortalController
{
    public function show(MetricsRangeRequest $request, AffiliateMetricsService $metrics, PlatformSettingsService $settings, MetricsCache $cache): JsonResponse
    {
        $affiliate = $this->affiliate($request);
        $range = DateRange::fromInput($request->rangeInput(), $settings->timezone());

        $result = $cache->remember('section', 'affiliate-portal.'.$affiliate->id, $range, [], static fn (): array => [
            'range' => $range->toArray(),
            'comparison_range' => $range->comparisonToArray(),
            'kpis' => array_map(static fn (KpiValue $k): array => $k->jsonSerialize(), $metrics->portal($affiliate, $range)),
        ]);

        return APIResponse::success($result['data'], meta: ['generated_at' => $result['generated_at'], 'cached' => $result['cached']]);
    }
}
