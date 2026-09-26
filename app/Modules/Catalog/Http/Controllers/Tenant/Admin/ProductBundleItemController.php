<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductBundleItem;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Child lines of a bundle (spec §28.6).
 */
final class ProductBundleItemController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function store(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'child_product_id' => ['required', 'integer', Rule::exists('tenant.products', 'id')->whereNull('deleted_at')],
            'child_product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $item = $this->products->addBundleItem(
            $product,
            Product::query()->findOrFail($validated['child_product_id']),
            (string) $validated['quantity'],
            isset($validated['child_product_variant_id']) ? ProductVariant::query()->findOrFail($validated['child_product_variant_id']) : null,
        );

        return APIResponse::created([
            'id' => $item->id,
            'child_product_id' => $item->child_product_id,
            'child_product_variant_id' => $item->child_product_variant_id,
            'quantity' => (string) $item->quantity,
        ], 'Bundle item added');
    }

    public function destroy(Product $product, int $item): JsonResponse
    {
        $this->products->removeBundleItem(ProductBundleItem::query()->where('bundle_product_id', $product->id)->whereKey($item)->firstOrFail());

        return APIResponse::noContent('Bundle item removed');
    }
}
