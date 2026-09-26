<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\CatalogPresenter;
use App\Modules\Promotions\Models\FlashSale;
use App\Modules\Promotions\Models\FlashSaleProduct;
use App\Modules\Promotions\Services\FlashSaleService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/flash-sales (spec §37.9): the running sales with their visible
 * products as storefront cards, plus the remaining sale quantity.
 */
final class FlashSaleController extends Controller
{
    public function index(FlashSaleService $sales, CatalogPresenter $catalog): JsonResponse
    {
        return APIResponse::success($sales->getActiveFlashSales()->map(static function (FlashSale $sale) use ($catalog): array {
            $cards = collect($catalog->storefrontCards($sale->products->map(static fn (FlashSaleProduct $p) => $p->product)))->keyBy('id');

            return [
                'id' => $sale->id,
                'name' => $sale->name,
                'starts_at' => $sale->starts_at->toIso8601String(),
                'ends_at' => $sale->ends_at->toIso8601String(),
                'products' => $sale->products->map(static fn (FlashSaleProduct $p): array => [
                    ...$cards[$p->product_id],
                    'sale_price' => (string) $p->sale_price,
                    'quantity_remaining' => $p->quantity_limit === null ? null : bcsub((string) $p->quantity_limit, (string) $p->quantity_claimed, 3),
                ])->values()->all(),
            ];
        })->values());
    }
}
