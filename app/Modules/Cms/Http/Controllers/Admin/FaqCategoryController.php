<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Cms\Http\CmsPresenter;
use App\Modules\Cms\Models\CmsFaqCategory;
use App\Modules\Cms\Services\CmsFaqService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FaqCategoryController extends Controller
{
    public function __construct(private readonly CmsFaqService $faqs) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->faqs->listCategories(false)->map(static fn (CmsFaqCategory $c): array => CmsPresenter::category($c, false))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(CmsPresenter::category($this->faqs->createCategory($request->all()), false), 'Category created');
    }

    public function update(Request $request, CmsFaqCategory $category): JsonResponse
    {
        return APIResponse::success(CmsPresenter::category($this->faqs->updateCategory($category, $request->all()), false), 'Category updated');
    }

    public function destroy(CmsFaqCategory $category): JsonResponse
    {
        $this->faqs->deleteCategory($category);

        return APIResponse::noContent('Category deleted');
    }
}
