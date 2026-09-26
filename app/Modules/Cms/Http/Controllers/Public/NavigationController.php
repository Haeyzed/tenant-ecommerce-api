<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsBanner;
use App\Modules\Cms\Services\CmsBannerService;
use App\Modules\Cms\Services\CmsMenuService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolved menus and live banners (spec §24.10).
 */
final class NavigationController extends Controller
{
    public function menu(string $key, CmsMenuService $menus): JsonResponse
    {
        return APIResponse::success($menus->getResolvedMenu($key) ?? throw new NotFoundHttpException('Menu not found.'));
    }

    public function banners(Request $request, CmsBannerService $banners): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['sometimes', Rule::in([CmsBanner::IMAGE, CmsBanner::ANNOUNCEMENT])],
            'position' => ['sometimes', 'string', 'max:64'],
        ]);

        $list = ($filters['kind'] ?? null) === CmsBanner::ANNOUNCEMENT
            ? $banners->getAnnouncementBar()
            : $banners->getLiveBanners($filters['position'] ?? null);

        return APIResponse::success($list->map(static fn (CmsBanner $b): array => CmsPresenter::banner($b, true))->values());
    }
}
