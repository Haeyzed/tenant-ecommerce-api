<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Http\MarketplacePresenter;
use App\Modules\Marketplace\Models\SellerGroup;
use App\Modules\Marketplace\Services\SellerGroupService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Seller groups (spec §50.1, §50.7).
 */
final class SellerGroupController extends Controller
{
    public function __construct(
        private readonly SellerGroupService $groups,
        private readonly MarketplacePresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->groups->listGroups()->map(fn (SellerGroup $g): array => $this->presenter->group($g))->values());
    }

    /**
     * Body: name, description?, default_commission_rate?
     */
    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->group($this->groups->createGroup($request->only(['name', 'description', 'default_commission_rate']))), 'Seller group created');
    }

    public function update(Request $request, SellerGroup $group): JsonResponse
    {
        return APIResponse::success($this->presenter->group($this->groups->updateGroup($group, $request->only(['name', 'description', 'default_commission_rate']))), 'Seller group updated');
    }

    public function destroy(SellerGroup $group): JsonResponse
    {
        $this->groups->deleteGroup($group);

        return APIResponse::success(null, 'Seller group deleted');
    }
}
