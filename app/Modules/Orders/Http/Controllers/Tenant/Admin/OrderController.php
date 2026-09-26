<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Order administration (spec §39.7). A staff user narrowed to warehouses
 * sees orders with at least one line in them (§25.3).
 */
final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderPaymentService $payments,
        private readonly OrderPresenter $presenter,
        private readonly WarehouseService $warehouses,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(Order::STATUSES)],
            'payment_status' => ['sometimes', Rule::in(Order::PAYMENT_STATUSES)],
            'order_source' => ['sometimes', Rule::in(Order::SOURCES)],
            'order_type' => ['sometimes', Rule::in(Order::TYPES)],
            'customer_id' => ['sometimes', 'integer'],
            'warehouse_id' => ['sometimes', 'integer'],
            'promotion_id' => ['sometimes', 'integer'],
            'is_test' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (array_key_exists('is_test', $filters)) {
            $filters['is_test'] = $request->boolean('is_test');
        }

        return APIResponse::success($this->orders->listOrders($filters, $this->actor($request))->through(fn (Order $o): array => $this->presenter->order($o, false, true)));
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->assertVisible($request, $order);

        return APIResponse::success($this->present($order));
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->assertVisible($request, $order);
        $status = (string) $request->validate(['status' => ['required', Rule::in(Order::STATUSES)]])['status'];

        return APIResponse::success($this->present($this->orders->updateOrderStatus($order, $status)), 'Status updated');
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->assertVisible($request, $order);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        return APIResponse::success($this->present($this->orders->cancelOrder($order, $reason)), 'Order cancelled');
    }

    /**
     * A direct refund with no return (§39.5), newest payments first.
     */
    public function refund(Request $request, Order $order): JsonResponse
    {
        $this->assertVisible($request, $order);
        $validated = $request->validate([
            'amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $refunds = $this->payments->refundOrder($order, isset($validated['amount']) ? (string) $validated['amount'] : null, $validated['reason'],
            $this->actor($request), $request->attributes->get('idempotency_key'));

        return APIResponse::success([
            'refunds' => array_map(fn (OrderPayment $r): array => $this->presenter->payment($r), $refunds),
            'order' => $this->present($order->refresh()),
        ], 'Refund recorded');
    }

    public function duplicate(Request $request, Order $order, CheckoutService $checkout): JsonResponse
    {
        $this->assertVisible($request, $order);

        return APIResponse::created($this->present($checkout->duplicateOrder($order, $this->actor($request))), 'Order duplicated');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Order $order): array
    {
        return $this->presenter->order($this->orders->getOrder($order), true, true, $this->payments->balance($order));
    }

    private function assertVisible(Request $request, Order $order): void
    {
        $visible = $this->warehouses->visibleIds($this->actor($request));

        if ($visible !== null && ! $order->items()->whereIn('warehouse_id', $visible)->exists()) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);
        }
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
