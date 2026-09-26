<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\CatalogPresenter;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryService;
use App\Modules\Cms\Support\CmsMedia;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Categories (spec §27.5).
 */
final class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryService $categories,
        private readonly CatalogPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tree = $request->boolean('tree');
        $list = $this->categories->listCategories(['tree' => $tree]);

        return APIResponse::success($tree
            ? $this->presenter->categoryTree($list, false)
            : $list->map(fn (Category $c): array => $this->presenter->category($c, false))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->category($this->categories->createCategory($request->all()), false), 'Category created');
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        return APIResponse::success($this->presenter->category($this->categories->updateCategory($category, $request->all()), false), 'Category updated');
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->categories->deleteCategory($category);

        return APIResponse::noContent('Category deleted');
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate(['ordered_ids' => ['required', 'array', 'min:1'], 'ordered_ids.*' => ['integer']])['ordered_ids'];
        $this->categories->reorderCategories($ids);

        return APIResponse::success(null, 'Categories reordered');
    }

    /**
     * Multipart "image"; collection image (default) or og_image.
     */
    public function image(Request $request, Category $category, CmsMedia $media): JsonResponse
    {
        $validated = $request->validate(['image' => ['required', 'file'], 'collection' => ['sometimes', Rule::in(['image', 'og_image'])]]);
        /** @var UploadedFile $upload */
        $upload = $request->file('image');
        $stored = $media->store($category, $validated['collection'] ?? 'image', $upload);

        return APIResponse::created(['id' => $stored->id, 'collection' => $stored->collection_name, 'url' => $stored->getUrl()], 'Image uploaded');
    }
}
