<?php

declare(strict_types=1);

namespace App\Modules\Currency\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Currency\Http\CurrencyPresenter;
use App\Modules\Currency\Models\ProductPrice;
use App\Modules\Currency\Services\CurrencyService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Explicit market prices of a product (spec §48.4): authoritative for their
 * currency; products without one are converted at the reference rate.
 */
final class ProductPriceController extends Controller
{
    public function __construct(
        private readonly CurrencyService $currencies,
        private readonly CurrencyPresenter $presenter,
    ) {}

    public function index(Product $product): JsonResponse
    {
        return APIResponse::success($this->currencies->listProductPrices($product)->map(fn (ProductPrice $p): array => $this->presenter->price($p))->values());
    }

    /**
     * Body: currency_code, price, compare_at_price?, product_variant_id?.
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        return APIResponse::created($this->presenter->price($this->currencies->setProductPrice($product, $request->all())), 'Price added');
    }

    /**
     * Body: price?, compare_at_price?.
     */
    public function update(Request $request, Product $product, ProductPrice $price): JsonResponse
    {
        return APIResponse::success($this->presenter->price($this->currencies->setProductPrice($product, $request->all(), $this->owned($product, $price))), 'Price updated');
    }

    public function destroy(Product $product, ProductPrice $price): JsonResponse
    {
        $this->currencies->deleteProductPrice($this->owned($product, $price));

        return APIResponse::success(null, 'Price removed');
    }

    private function owned(Product $product, ProductPrice $price): ProductPrice
    {
        if ($price->product_id !== $product->id) {
            throw new NotFoundHttpException;
        }

        return $price;
    }
}
