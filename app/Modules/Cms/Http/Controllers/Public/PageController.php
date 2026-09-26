<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Services\CmsPageService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Published pages with their active sections (spec §24.10).
 */
final class PageController extends Controller
{
    public function __construct(private readonly CmsPageService $pages) {}

    public function home(): JsonResponse
    {
        $page = $this->pages->getHomepage() ?? throw new NotFoundHttpException('No homepage is published.');

        return APIResponse::success(CmsPresenter::page($page->load('sections', 'media'), true));
    }

    public function show(string $slug): JsonResponse
    {
        $page = $this->pages->getBySlug($slug, true) ?? throw new NotFoundHttpException('Page not found.');

        return APIResponse::success(CmsPresenter::page($page->load('sections', 'media'), true));
    }
}
