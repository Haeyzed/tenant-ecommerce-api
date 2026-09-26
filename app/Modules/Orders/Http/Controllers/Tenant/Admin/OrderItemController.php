<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/admin/orders/{order}/items/{item}/warehouse (spec §32.4,
 * orders.items.warehouse). The line is looked up within its order.
 */
final class OrderItemController extends Controller
{
    public function changeWarehouse(Request $request, Order $order, int $item, OrderService $orders, OrderPresenter $presenter): JsonResponse
    {
        $warehouseId = (int) $request->validate(['warehouse_id' => ['required', 'integer', Rule::exists('tenant.warehouses', 'id')]])['warehouse_id'];
        /** @var OrderItem $line */
        $line = OrderItem::query()->where('order_id', $order->id)->whereKey($item)->firstOrFail();

        $line = $orders->changeLineWarehouse($line, Warehouse::query()->findOrFail($warehouseId));

        return APIResponse::success($presenter->item($line->load('warehouse', 'product'), true), 'Warehouse changed');
    }
}
