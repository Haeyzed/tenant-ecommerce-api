<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/products/{product}/inventory (spec §32.10): the
 * per-warehouse breakdown (products.inventory.view). Variable products
 * report per variant; bundles report their derived availability.
 */
final class ProductInventoryController extends Controller
{
    public function index(Product $product, InventoryService $inventory): JsonResponse
    {
        $data = match ($product->product_type) {
            Product::SIMPLE => ['tracked' => true, ...$inventory->getStockForProduct($product)],
            Product::VARIABLE => [
                'tracked' => true,
                'total_available' => $inventory->getAvailableStock($product),
                'variants' => $product->variants()->get()->map(static fn (ProductVariant $v): array => [
                    'variant_id' => $v->id,
                    'sku' => $v->sku,
                    'is_active' => $v->is_active,
                    ...$inventory->getStockForProduct($product, $v),
                ])->values()->all(),
            ],
            Product::BUNDLE => ['tracked' => false, 'derived' => true, 'total_available' => $inventory->getAvailableStock($product)],
            default => ['tracked' => false, 'derived' => false, 'total_available' => null],
        };

        return APIResponse::success($data);
    }
}
