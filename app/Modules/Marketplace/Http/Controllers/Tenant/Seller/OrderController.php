<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Http\Controllers\Tenant\Seller;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The seller's order lines, grouped by order (spec §50.7).
 */
final class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(Order::STATUSES)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var Seller $seller */
        $seller = $request->user();

        return APIResponse::success($this->orders->getOrderItemsForSeller($seller->id, $filters)->through(static fn (Order $o): array => [
            'order_id' => $o->id,
            'order_number' => $o->order_number,
            'status' => $o->status,
            'placed_at' => $o->placed_at->toIso8601String(),
            'currency_code' => $o->currency_code,
            'items' => $o->items->map(static fn (OrderItem $i): array => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->name_snapshot,
                'sku' => $i->sku_snapshot,
                'quantity' => (string) $i->quantity,
                'quantity_shipped' => (string) $i->quantity_shipped,
                'unit_price' => (string) $i->unit_price,
                'seller_funded_discount_amount' => (string) $i->seller_funded_discount_amount,
                'line_total' => (string) $i->line_total,
            ])->values()->all(),
        ]));
    }
}
