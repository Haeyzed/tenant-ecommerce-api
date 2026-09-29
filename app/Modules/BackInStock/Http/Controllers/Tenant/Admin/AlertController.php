<?php

declare(strict_types=1);

namespace App\Modules\BackInStock\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\BackInStock\Models\BackInStockSubscription;
use App\Modules\BackInStock\Services\BackInStockService;
use App\Modules\Catalog\Models\Product;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/products/{product}/back-in-stock-subscribers (spec §56):
 * the demand waiting for a restock, by variant.
 */
final class AlertController extends Controller
{
    public function __construct(private readonly BackInStockService $alerts) {}

    public function index(Product $product): JsonResponse
    {
        $rows = $this->alerts->listPendingForProduct($product);

        return APIResponse::success([
            'product_id' => $product->id,
            'waiting' => $rows->count(),
            'by_variant' => $rows->groupBy(static fn (BackInStockSubscription $r): string => (string) ($r->product_variant_id ?? 'any'))
                ->map(static fn ($group, string $key): array => [
                    'product_variant_id' => $key === 'any' ? null : (int) $key,
                    'sku' => $group->first()?->variant?->sku,
                    'waiting' => $group->count(),
                ])->values()->all(),
            'subscribers' => $rows->map(static fn (BackInStockSubscription $r): array => [
                'id' => $r->id,
                'email' => $r->email,
                'customer' => $r->customer === null ? null : ['id' => $r->customer->id, 'name' => $r->customer->name],
                'product_variant_id' => $r->product_variant_id,
                'sku' => $r->variant?->sku,
                'created_at' => $r->created_at->toIso8601String(),
            ])->all(),
        ]);
    }
}
