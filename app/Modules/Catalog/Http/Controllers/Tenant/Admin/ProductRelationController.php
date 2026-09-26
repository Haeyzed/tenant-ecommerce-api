<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Services\ProductContentService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Product relations (spec §29.8).
 */
final class ProductRelationController extends Controller
{
    public function store(Request $request, Product $product, ProductContentService $content): JsonResponse
    {
        $validated = $request->validate([
            'related_product_id' => ['required', 'integer', Rule::exists('tenant.products', 'id')->whereNull('deleted_at')],
            'relation_type' => ['required', Rule::in(ProductRelation::TYPES)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $relation = $content->addRelation($product, Product::query()->findOrFail($validated['related_product_id']), $validated['relation_type'], (int) ($validated['sort_order'] ?? 0));

        return APIResponse::created($relation->only(['id', 'related_product_id', 'relation_type', 'sort_order']), 'Relation added');
    }

    public function destroy(Product $product, int $relation, ProductContentService $content): JsonResponse
    {
        $content->removeRelation(ProductRelation::query()->where('product_id', $product->id)->whereKey($relation)->firstOrFail());

        return APIResponse::noContent('Relation removed');
    }
}
