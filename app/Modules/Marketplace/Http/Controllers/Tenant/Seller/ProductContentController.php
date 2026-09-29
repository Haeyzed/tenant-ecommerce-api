<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Tenant\Seller;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\DigitalFileController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductBundleItemController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductDetailsController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductMediaController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductOptionController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\ProductVariantController;
use App\Modules\Catalog\Http\Controllers\Tenant\Admin\UnitController;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductContentService;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Services\SellerProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The parts of a seller's own product (spec §50.3): variants, media,
 * digital files, bundle lines and specifications. Each action checks that
 * the product is the seller's, then runs the same catalogue action staff
 * use, so validation and responses are identical. The store-wide option
 * and unit lists are read-only here; badges and related products stay
 * with staff (merchandising).
 */
final class ProductContentController extends Controller
{
    public function __construct(private readonly SellerProductService $products) {}

    public function variants(Request $request, Product $product): JsonResponse
    {
        return app(ProductVariantController::class)->index($this->own($request, $product));
    }

    public function storeVariant(Request $request, Product $product): JsonResponse
    {
        return app(ProductVariantController::class)->store($request, $this->own($request, $product));
    }

    public function updateVariant(Request $request, Product $product, int $variant): JsonResponse
    {
        return app(ProductVariantController::class)->update($request, $this->own($request, $product), $variant);
    }

    public function destroyVariant(Request $request, Product $product, int $variant): JsonResponse
    {
        return app(ProductVariantController::class)->destroy($this->own($request, $product), $variant);
    }

    public function storeMedia(Request $request, Product $product): JsonResponse
    {
        return app(ProductMediaController::class)->store($request, $this->own($request, $product));
    }

    public function reorderMedia(Request $request, Product $product): JsonResponse
    {
        return app(ProductMediaController::class)->reorder($request, $this->own($request, $product));
    }

    public function destroyMedia(Request $request, Product $product, int $media): JsonResponse
    {
        return app(ProductMediaController::class)->destroy($this->own($request, $product), $media);
    }

    public function featuredMedia(Request $request, Product $product, int $media): JsonResponse
    {
        return app(ProductMediaController::class)->setFeatured($this->own($request, $product), $media);
    }

    public function storeDigitalFile(Request $request, Product $product): JsonResponse
    {
        return app(DigitalFileController::class)->store($request, $this->own($request, $product));
    }

    public function destroyDigitalFile(Request $request, Product $product, int $file): JsonResponse
    {
        return app(DigitalFileController::class)->destroy($this->own($request, $product), $file);
    }

    /**
     * Body: child_product_id (one of the seller's own), child_product_variant_id?, quantity.
     */
    public function storeBundleItem(Request $request, Product $product): JsonResponse
    {
        $product = $this->own($request, $product);
        $this->products->assertOwnBundleChildren($this->seller($request), [$request->only('child_product_id')]);

        return app(ProductBundleItemController::class)->store($request, $product);
    }

    public function destroyBundleItem(Request $request, Product $product, int $item): JsonResponse
    {
        return app(ProductBundleItemController::class)->destroy($this->own($request, $product), $item);
    }

    public function specifications(Request $request, Product $product): JsonResponse
    {
        return app(ProductDetailsController::class)->update($request, $this->own($request, $product), app(ProductContentService::class));
    }

    /**
     * The store's options and values (Size, Colour…) that variants are built from.
     */
    public function options(): JsonResponse
    {
        return app(ProductOptionController::class)->index();
    }

    public function units(): JsonResponse
    {
        return app(UnitController::class)->index();
    }

    private function own(Request $request, Product $product): Product
    {
        return $this->products->own($this->seller($request), $product);
    }

    private function seller(Request $request): Seller
    {
        /** @var Seller */
        return $request->user();
    }
}
