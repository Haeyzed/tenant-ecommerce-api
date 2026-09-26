<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsMenu;
use App\Modules\Cms\Services\CmsMenuService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MenuController extends Controller
{
    public function __construct(private readonly CmsMenuService $menus) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->menus->listMenus()->map(static fn (CmsMenu $m): array => CmsPresenter::menu($m))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(CmsPresenter::menu($this->menus->createMenu($request->all())->load('items')), 'Menu created');
    }

    public function update(Request $request, CmsMenu $menu): JsonResponse
    {
        return APIResponse::success(CmsPresenter::menu($this->menus->updateMenu($menu, $request->all())->load('items')), 'Menu updated');
    }

    public function destroy(CmsMenu $menu): JsonResponse
    {
        $this->menus->deleteMenu($menu);

        return APIResponse::noContent('Menu deleted');
    }

    public function syncItems(Request $request, CmsMenu $menu): JsonResponse
    {
        $items = $request->validate(['items' => ['present', 'array', 'max:200']])['items'];

        return APIResponse::success(CmsPresenter::menu($this->menus->syncItems($menu, $items)), 'Menu items saved');
    }
}
