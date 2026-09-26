<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Portal;

use App\Modules\Affiliates\Http\Resources\AffiliateResource;
use App\Modules\Affiliates\Services\AffiliateService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProfileController extends PortalController
{
    public function __construct(private readonly AffiliateService $affiliates) {}

    public function show(Request $request): JsonResponse
    {
        return APIResponse::success((new AffiliateResource($this->affiliate($request)))->forPortal());
    }

    public function update(Request $request): JsonResponse
    {
        $affiliate = $this->affiliates->updateProfile($this->affiliate($request), $request->only(['name', 'phone', 'company_name', 'website_url']));

        return APIResponse::success((new AffiliateResource($affiliate))->forPortal(), 'Profile updated');
    }

    public function payoutDetails(Request $request): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'max:255']]);

        $affiliate = $this->affiliates->updatePayoutDetails(
            $this->affiliate($request),
            $request->only(['payout_method', 'details']),
            (string) $request->input('current_password'),
        );

        return APIResponse::success((new AffiliateResource($affiliate))->forPortal(), 'Payout details updated');
    }
}
