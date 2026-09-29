<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Http\RestaurantPresenter;
use App\Modules\Restaurant\Models\ModifierGroup;
use App\Modules\Restaurant\Models\ModifierOption;
use App\Modules\Restaurant\Services\ModifierGroupService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A group's options (spec §65.5).
 */
final class ModifierOptionController extends Controller
{
    public function __construct(
        private readonly ModifierGroupService $groups,
        private readonly RestaurantPresenter $presenter,
    ) {}

    /**
     * Body: name, price_adjustment?, is_active?
     */
    public function store(Request $request, ModifierGroup $group): JsonResponse
    {
        return APIResponse::created($this->presenter->option($this->groups->addOption($group, $request->only(['name', 'price_adjustment', 'is_active']))), 'Option added');
    }

    public function update(Request $request, ModifierGroup $group, ModifierOption $option): JsonResponse
    {
        return APIResponse::success($this->presenter->option($this->groups->updateOption($this->of($group, $option), $request->only(['name', 'price_adjustment', 'is_active']))), 'Option updated');
    }

    public function destroy(ModifierGroup $group, ModifierOption $option): JsonResponse
    {
        $this->groups->deleteOption($this->of($group, $option));

        return APIResponse::success(null, 'Option deleted');
    }

    private function of(ModifierGroup $group, ModifierOption $option): ModifierOption
    {
        return $option->modifier_group_id === $group->id ? $option : throw new NotFoundHttpException('Not found.');
    }
}
