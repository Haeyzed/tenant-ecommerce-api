<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsBlogCategory;
use App\Modules\Cms\Services\CmsBlogService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BlogCategoryController extends Controller
{
    public function __construct(private readonly CmsBlogService $blog) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->blog->listCategories(false)->map(static fn (CmsBlogCategory $c): array => CmsPresenter::category($c, false))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(CmsPresenter::category($this->blog->createCategory($request->all()), false), 'Category created');
    }

    public function update(Request $request, CmsBlogCategory $category): JsonResponse
    {
        return APIResponse::success(CmsPresenter::category($this->blog->updateCategory($category, $request->all()), false), 'Category updated');
    }

    public function destroy(CmsBlogCategory $category): JsonResponse
    {
        $this->blog->deleteCategory($category);

        return APIResponse::noContent('Category deleted');
    }
}
