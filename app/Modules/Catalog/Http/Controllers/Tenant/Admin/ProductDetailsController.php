<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogReferenceService;
use App\Modules\Catalog\Services\CategoryService;
use App\Modules\Catalog\Services\ProductContentService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Whole-set replacements of a product's categories, tags and
 * specifications (spec §27.5 ProductCategoryController@sync, §29.8
 * ProductTagController@sync and ProductSpecificationController@update).
 * The derived permissions are products.categories, products.tags and
 * products.specifications.update.
 */
final class ProductDetailsController extends Controller
{
    public function categories(Request $request, Product $product, CategoryService $categories): JsonResponse
    {
        $validated = $request->validate([
            'category_ids' => ['present', 'array', 'max:50'],
            'category_ids.*' => ['integer'],
            'primary_category_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $categories->attachCategories($product, $validated['category_ids'], isset($validated['primary_category_id']) ? (int) $validated['primary_category_id'] : null);

        return APIResponse::success($categories->getCategoriesForProduct($product)
            ->map(static fn ($c): array => ['id' => $c->id, 'name' => $c->name, 'is_primary' => (bool) $c->pivot->is_primary])->values(), 'Categories updated');
    }

    public function tags(Request $request, Product $product, CatalogReferenceService $references): JsonResponse
    {
        $ids = $request->validate(['tag_ids' => ['present', 'array', 'max:100'], 'tag_ids.*' => ['integer']])['tag_ids'];
        $references->syncProductTags($product, $ids);

        return APIResponse::success($product->tags()->orderBy('name')->get(['tags.id', 'tags.name', 'tags.slug']), 'Tags updated');
    }

    public function update(Request $request, Product $product, ProductContentService $content): JsonResponse
    {
        $specs = $content->setSpecifications($product, (array) $request->input('specifications', []));

        return APIResponse::success($specs->map(static fn ($s): array => $s->only(['id', 'spec_group', 'spec_key', 'spec_value', 'sort_order']))->values(), 'Specifications updated');
    }
}
