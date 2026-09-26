<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Modules\Affiliates\Services\AffiliateTrackingService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Called by the website when a page opens with ?ref=CODE (spec §21A.3).
 */
final class AffiliateClickController extends Controller
{
    public function store(Request $request, AffiliateTrackingService $tracking): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'visitor_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'landing_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'referrer' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'utm_source' => ['sometimes', 'nullable', 'string', 'max:255'],
            'utm_medium' => ['sometimes', 'nullable', 'string', 'max:255'],
            'utm_campaign' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $result = $tracking->recordClick((string) $validated['code'], $validated, $request);

        return APIResponse::success([
            'referral_token' => $result['referral_token'],
            'visitor_id' => $result['visitor_id'],
            'expires_at' => $result['expires_at'],
        ]);
    }
}
