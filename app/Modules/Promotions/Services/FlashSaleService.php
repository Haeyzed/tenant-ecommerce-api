<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\Order;
use App\Modules\Promotions\Models\FlashSale;
use App\Modules\Promotions\Models\FlashSaleClaim;
use App\Modules\Promotions\Models\FlashSaleProduct;
use App\Modules\Promotions\Support\FlashSalePrices;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Flash sales (spec §37.7, §37.8): a sale price per product for a window,
 * with an optional quantity cap. A product is never in two sales whose
 * windows overlap (Assumption A-20). Claims and releases arrive with
 * orders.
 */
final readonly class FlashSaleService
{
    public function __construct(private FlashSalePrices $prices) {}

    /**
     * @param  array{status?: string}  $filters
     * @return Collection<int, FlashSale>
     */
    public function listFlashSales(array $filters = []): Collection
    {
        $now = now();

        return FlashSale::query()->with('products.product:id,name,sku,price')
            ->when(($filters['status'] ?? null) === 'scheduled', static fn ($q) => $q->where('starts_at', '>', $now))
            ->when(($filters['status'] ?? null) === 'running', static fn ($q) => $q->where('is_active', true)->where('starts_at', '<=', $now)->where('ends_at', '>', $now))
            ->when(($filters['status'] ?? null) === 'ended', static fn ($q) => $q->where('ends_at', '<=', $now))
            ->orderByDesc('starts_at')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFlashSale(array $data): FlashSale
    {
        /** @var FlashSale $sale */
        $sale = FlashSale::query()->create($this->validate($data, null));
        $this->prices->flush();

        return $sale->refresh()->load('products.product:id,name,sku,price');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateFlashSale(FlashSale $sale, array $data): FlashSale
    {
        $validated = $this->validate($data, $sale);

        DB::connection('tenant')->transaction(function () use ($sale, $validated): void {
            $sale->fill($validated);

            if ($sale->isDirty(['starts_at', 'ends_at'])) {
                foreach ($sale->products()->pluck('product_id') as $productId) {
                    $this->assertNoOverlap((int) $productId, $sale->starts_at, $sale->ends_at, $sale->id);
                }
            }

            $sale->save();
        });

        $this->prices->flush();

        return $sale->load('products.product:id,name,sku,price');
    }

    /**
     * Blocked while any quantity is claimed: end the sale instead.
     */
    public function deleteFlashSale(FlashSale $sale): void
    {
        if ($sale->products()->where('quantity_claimed', '>', 0)->exists()) {
            throw ApiException::unprocessable('flash_sale_claimed', 'Orders hold sale quantity. End the sale instead.');
        }

        $sale->delete();
        $this->prices->flush();
    }

    public function addProduct(FlashSale $sale, Product $product, string $salePrice, ?string $quantityLimit = null): FlashSaleProduct
    {
        validator(['sale_price' => $salePrice, 'quantity_limit' => $quantityLimit], [
            'sale_price' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'quantity_limit' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3'],
        ])->validate();

        if (Money::cmp(Money::normalize($salePrice), Money::normalize((string) $product->price)) >= 0) {
            throw ValidationException::withMessages(['sale_price' => ['The sale price must be below the product price.']]);
        }

        $row = DB::connection('tenant')->transaction(function () use ($sale, $product, $salePrice, $quantityLimit): FlashSaleProduct {
            // Serialises additions of this product across sales.
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            if ($sale->products()->where('product_id', $product->id)->exists()) {
                throw ApiException::conflict('flash_sale_product_exists', 'The product is already in this sale.');
            }

            $this->assertNoOverlap($product->id, $sale->starts_at, $sale->ends_at, $sale->id);

            /** @var FlashSaleProduct */
            return $sale->products()->create([
                'product_id' => $product->id,
                'sale_price' => Money::normalize($salePrice),
                'quantity_limit' => $quantityLimit,
            ]);
        });

        $this->prices->flush();

        return $row->refresh()->load('product:id,name,sku,price');
    }

    public function removeProduct(FlashSale $sale, Product $product): void
    {
        $row = $sale->products()->where('product_id', $product->id)->firstOrFail();

        if (bccomp((string) $row->quantity_claimed, '0', 3) > 0) {
            throw ApiException::unprocessable('flash_sale_claimed', 'Orders hold sale quantity of this product.');
        }

        $row->delete();
        $this->prices->flush();
    }

    /**
     * Running sales with their products (the storefront list).
     *
     * @return Collection<int, FlashSale>
     */
    public function getActiveFlashSales(): Collection
    {
        return FlashSale::query()
            ->where('is_active', true)->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->with(['products' => static fn ($q) => $q->whereHas('product', static fn ($p) => $p->visible()), 'products.product.brand', 'products.product.media', 'products.product.badges'])
            ->orderBy('ends_at')
            ->get();
    }

    public function getFlashSalePrice(Product $product): ?string
    {
        return $this->prices->priceFor($product->id);
    }

    /**
     * Claims the sale quantity of the order's flash-sale lines (§37.7), in
     * the checkout transaction: the running sale rows are locked in
     * ascending id order; a sold-out or ended sale is 409 price_changed.
     */
    public function claim(Order $order): void
    {
        $quantities = [];

        foreach ($order->items as $item) {
            if ($item->price_source === 'flash_sale' && $item->product_id !== null) {
                $quantities[$item->product_id] = Quantity::add($quantities[$item->product_id] ?? '0', (string) $item->quantity);
            }
        }

        if ($quantities === []) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($order, $quantities): void {
            $now = now();
            $rows = FlashSaleProduct::query()
                ->whereIn('product_id', array_keys($quantities))
                ->whereHas('flashSale', static fn ($q) => $q->where('is_active', true)->where('starts_at', '<=', $now)->where('ends_at', '>', $now))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('product_id');

            foreach ($quantities as $productId => $quantity) {
                // The row that priced the line: the lowest running sale price.
                /** @var FlashSaleProduct|null $row */
                $row = ($rows[$productId] ?? collect())->sortBy([['sale_price', 'asc'], ['id', 'asc']])->first();

                if ($row === null || ($row->quantity_limit !== null && Quantity::cmp(Quantity::add((string) $row->quantity_claimed, $quantity), (string) $row->quantity_limit) > 0)) {
                    throw ApiException::conflict('price_changed', 'A sale price on this basket is no longer available. Review the cart.', ['product_id' => $productId]);
                }

                $row->forceFill(['quantity_claimed' => Quantity::add((string) $row->quantity_claimed, $quantity)])->save();

                $claim = new FlashSaleClaim;
                $claim->forceFill(['flash_sale_product_id' => $row->id, 'order_id' => $order->id, 'quantity' => $quantity, 'status' => FlashSaleClaim::CLAIMED])->save();
            }
        });

        $this->prices->flush();
    }

    /**
     * Returns the order's claimed quantity to its sale rows (unpaid expiry
     * and cancellation before completion). Idempotent by claim status.
     */
    public function release(Order $order): void
    {
        $released = DB::connection('tenant')->transaction(static function () use ($order): bool {
            $claims = FlashSaleClaim::query()->where('order_id', $order->id)->where('status', FlashSaleClaim::CLAIMED)->orderBy('flash_sale_product_id')->lockForUpdate()->get();

            if ($claims->isEmpty()) {
                return false;
            }

            $rows = FlashSaleProduct::query()->whereKey($claims->pluck('flash_sale_product_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($claims as $claim) {
                $row = $rows->get($claim->flash_sale_product_id);
                $row?->forceFill(['quantity_claimed' => Quantity::max('0', Quantity::sub((string) $row->quantity_claimed, (string) $claim->quantity))])->save();
                $claim->forceFill(['status' => FlashSaleClaim::RELEASED])->save();
            }

            return true;
        });

        if ($released) {
            $this->prices->flush();
        }
    }

    private function assertNoOverlap(int $productId, Carbon $startsAt, Carbon $endsAt, ?int $ignoreSaleId): void
    {
        $overlap = FlashSaleProduct::query()
            ->where('product_id', $productId)
            ->whereHas('flashSale', static fn ($q) => $q
                ->when($ignoreSaleId !== null, static fn ($w) => $w->whereKeyNot($ignoreSaleId))
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt))
            ->exists();

        if ($overlap) {
            throw ApiException::unprocessable('flash_sale_overlap', 'The product is already in a flash sale during this window.', ['product_id' => $productId]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?FlashSale $existing): array
    {
        $req = $existing === null ? 'required' : 'sometimes';

        $validated = validator($data, [
            'name' => [$req, 'string', 'max:255'],
            'starts_at' => [$req, 'date'],
            'ends_at' => [$req, 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        $starts = $validated['starts_at'] ?? $existing?->starts_at;
        $ends = $validated['ends_at'] ?? $existing?->ends_at;

        if (Carbon::parse($starts)->gte(Carbon::parse($ends))) {
            throw ValidationException::withMessages(['ends_at' => ['The end must be after the start.']]);
        }

        return $validated;
    }
}
