<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Services\CmsPageService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CMS pages (spec §24.10), in the scope of the domain the request came to.
 */
final class PageController extends Controller
{
    public function __construct(private readonly CmsPageService $pages) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([CmsPage::DRAFT, CmsPage::PUBLISHED])],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->pages->listPages($filters)->through(static fn (CmsPage $p): array => CmsPresenter::page($p, false, false)));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(CmsPresenter::page($this->pages->createPage($request->all())->load('sections', 'media'), false), 'Page created');
    }

    public function show(CmsPage $page): JsonResponse
    {
        return APIResponse::success(CmsPresenter::page($page->load('sections', 'media'), false));
    }

    public function update(Request $request, CmsPage $page): JsonResponse
    {
        return APIResponse::success(CmsPresenter::page($this->pages->updatePage($page, $request->all())->load('sections', 'media'), false), 'Page updated');
    }

    public function destroy(CmsPage $page): JsonResponse
    {
        $this->pages->deletePage($page);

        return APIResponse::noContent('Page deleted');
    }

    public function publish(CmsPage $page): JsonResponse
    {
        return APIResponse::success(CmsPresenter::page($this->pages->publishPage($page)->load('sections', 'media'), false), 'Page published');
    }

    public function unpublish(CmsPage $page): JsonResponse
    {
        return APIResponse::success(CmsPresenter::page($this->pages->unpublishPage($page)->load('sections', 'media'), false), 'Page unpublished');
    }

    public function setHomepage(CmsPage $page): JsonResponse
    {
        return APIResponse::success(CmsPresenter::page($this->pages->setHomepage($page)->load('sections', 'media'), false), 'Homepage set');
    }
}
