<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Restaurant\Http\RestaurantPresenter;
use App\Modules\Restaurant\Models\ModifierGroup;
use App\Modules\Restaurant\Services\ModifierGroupService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Modifier groups and their product links (spec §65.5).
 */
final class ModifierGroupController extends Controller
{
    public function __construct(
        private readonly ModifierGroupService $groups,
        private readonly RestaurantPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['product_id' => ['sometimes', 'integer']]);

        return APIResponse::success($this->groups->listGroups($filters)->map(fn (ModifierGroup $g): array => $this->presenter->group($g))->values()->all());
    }

    /**
     * Body: name, selection_type (single | multiple), is_required?, options[]? (name, price_adjustment?, is_active?)
     */
    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->group($this->groups->createGroup($request->only(['name', 'selection_type', 'is_required', 'options']))), 'Modifier group created');
    }

    public function update(Request $request, ModifierGroup $group): JsonResponse
    {
        return APIResponse::success($this->presenter->group($this->groups->updateGroup($group, $request->only(['name', 'selection_type', 'is_required']))), 'Modifier group updated');
    }

    public function destroy(ModifierGroup $group): JsonResponse
    {
        $this->groups->deleteGroup($group);

        return APIResponse::success(null, 'Modifier group deleted');
    }

    public function attach(Product $product, ModifierGroup $group): JsonResponse
    {
        $this->groups->attachToProduct($product, $group);

        return APIResponse::success(null, 'Modifier group attached');
    }

    public function detach(Product $product, ModifierGroup $group): JsonResponse
    {
        $this->groups->detachFromProduct($product, $group);

        return APIResponse::success(null, 'Modifier group detached');
    }
}
