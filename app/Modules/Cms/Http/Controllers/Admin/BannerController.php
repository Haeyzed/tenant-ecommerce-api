<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsBanner;
use App\Modules\Cms\Services\CmsBannerService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BannerController extends Controller
{
    public function __construct(private readonly CmsBannerService $banners) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['sometimes', Rule::in([CmsBanner::IMAGE, CmsBanner::ANNOUNCEMENT])],
            'position' => ['sometimes', 'string', 'max:64'],
        ]);

        return APIResponse::success($this->banners->listBanners($filters)->map(static fn (CmsBanner $b): array => CmsPresenter::banner($b, false))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(CmsPresenter::banner($this->banners->createBanner($request->all()), false), 'Banner created');
    }

    public function update(Request $request, CmsBanner $banner): JsonResponse
    {
        return APIResponse::success(CmsPresenter::banner($this->banners->updateBanner($banner, $request->all()), false), 'Banner updated');
    }

    public function destroy(CmsBanner $banner): JsonResponse
    {
        $this->banners->deleteBanner($banner);

        return APIResponse::noContent('Banner deleted');
    }
}
