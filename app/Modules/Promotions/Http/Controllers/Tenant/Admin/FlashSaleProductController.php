<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Promotions\Http\PromotionPresenter;
use App\Modules\Promotions\Models\FlashSale;
use App\Modules\Promotions\Services\FlashSaleService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Products of a flash sale (spec §37.9).
 */
final class FlashSaleProductController extends Controller
{
    public function __construct(
        private readonly FlashSaleService $sales,
        private readonly PromotionPresenter $presenter,
    ) {}

    public function store(Request $request, FlashSale $sale): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('tenant.products', 'id')->whereNull('deleted_at')],
            'sale_price' => ['required'],
            'quantity_limit' => ['sometimes', 'nullable'],
        ]);

        $row = $this->sales->addProduct(
            $sale,
            Product::query()->findOrFail($validated['product_id']),
            (string) $validated['sale_price'],
            isset($validated['quantity_limit']) ? (string) $validated['quantity_limit'] : null,
        );

        return APIResponse::created($this->presenter->flashSaleProduct($row), 'Product added');
    }

    public function destroy(FlashSale $sale, Product $product): JsonResponse
    {
        $this->sales->removeProduct($sale, $product);

        return APIResponse::noContent('Product removed');
    }
}
