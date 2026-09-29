<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\ProjectPresenter;
use App\Modules\Projects\Models\ProjectCategory;
use App\Modules\Projects\Services\ProjectCategoryService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProjectCategoryController extends Controller
{
    public function __construct(
        private readonly ProjectCategoryService $categories,
        private readonly ProjectPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::in(ProjectCategory::STATUSES)]]);

        return APIResponse::success($this->categories->listCategories($filters)->map(fn (ProjectCategory $c): array => $this->presenter->category($c))->all());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->category($this->categories->createCategory($request->only(['name', 'status']))), 'Category created');
    }

    public function update(Request $request, ProjectCategory $category): JsonResponse
    {
        return APIResponse::success($this->presenter->category($this->categories->updateCategory($category, $request->only(['name', 'status']))), 'Category updated');
    }

    public function destroy(ProjectCategory $category): JsonResponse
    {
        $this->categories->deleteCategory($category);

        return APIResponse::success(null, 'Category deleted');
    }
}
