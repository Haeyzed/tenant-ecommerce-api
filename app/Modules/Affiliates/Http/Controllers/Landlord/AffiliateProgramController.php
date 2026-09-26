<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Modules\Affiliates\Services\AffiliateService;
use App\Modules\Legal\Services\LegalDocumentService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * Public programme terms for the website (spec §21A.10).
 */
final class AffiliateProgramController extends Controller
{
    public function show(AffiliateService $affiliates, PlatformSettingsService $settings, LegalDocumentService $legal): JsonResponse
    {
        $agreement = $legal->current('affiliate_agreement');

        return APIResponse::success([
            'is_open' => $affiliates->programmeEnabled() && $agreement !== null,
            'default_commission_rate' => bcadd((string) $settings->get('affiliate_default_commission_rate', '20'), '0', 4),
            'cookie_days' => (int) $settings->get('affiliate_cookie_days', 30),
            'commission_hold_days' => (int) $settings->get('affiliate_commission_hold_days', 30),
            'payout_schedule' => (string) $settings->get('affiliate_payout_schedule', 'monthly'),
            'payout_day' => (int) $settings->get('affiliate_payout_day', 1),
            'minimum_payout' => array_map(static fn ($v): string => bcadd((string) $v, '0', 4), (array) $settings->get('affiliate_minimum_payout', [])),
            'agreement' => $agreement === null ? null : [
                'id' => $agreement->id,
                'title' => $agreement->title,
                'version' => $agreement->version,
                'effective_at' => $agreement->effective_at?->toIso8601String(),
            ],
        ]);
    }
}
