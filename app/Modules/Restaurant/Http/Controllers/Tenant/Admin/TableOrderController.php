<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Pos\Services\PosSaleService;
use App\Modules\Restaurant\Http\RestaurantPresenter;
use App\Modules\Restaurant\Models\RestaurantTable;
use App\Modules\Restaurant\Services\RestaurantTableOrderService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Table orders (spec §65.4): open, add rounds, settle with the POS
 * tenders, or void before payment.
 */
final class TableOrderController extends Controller
{
    public function __construct(
        private readonly RestaurantTableOrderService $tableOrders,
        private readonly PosSaleService $sales,
        private readonly RestaurantPresenter $presenter,
    ) {}

    public function current(RestaurantTable $table): JsonResponse
    {
        $order = $this->tableOrders->getCurrentOrder($table);

        return APIResponse::success($order === null ? null : $this->presenter->tableOrder($order));
    }

    /**
     * Body: lines[]? (product_id, variant_id?, quantity, modifier_option_ids[]?), customer_id?, customer_name?, customer_phone?
     */
    public function store(Request $request, RestaurantTable $table): JsonResponse
    {
        $options = $request->validate([
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        $order = $this->tableOrders->openTableOrder($table, array_values((array) $request->input('lines', [])), $options, $this->user($request));

        return APIResponse::created($this->presenter->tableOrder($order), 'Table order opened');
    }

    /**
     * Body: lines[] (product_id, variant_id?, quantity, modifier_option_ids[]?)
     */
    public function addItems(Request $request, Order $order): JsonResponse
    {
        $order = $this->tableOrders->addItems($order, array_values((array) $request->input('lines', [])));

        return APIResponse::success($this->presenter->tableOrder($order->refresh()), 'Sent to the kitchen');
    }

    /**
     * Body: register_id, payments[] (method, amount, reference?, gift_card_code?), pos_session_id?, quote_total?
     */
    public function settle(Request $request, Order $order): JsonResponse
    {
        $order = $this->tableOrders->settle($order, $request->all(), $this->user($request));

        return APIResponse::success(['order' => $this->presenter->tableOrder($order->refresh()), 'receipt' => $this->sales->generateReceipt($order)], 'Table order settled');
    }

    /**
     * Body: reason
     */
    public function void(Request $request, Order $order): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];

        return APIResponse::success($this->presenter->tableOrder($this->tableOrders->voidTableOrder($order, $reason)->refresh()), 'Table order voided');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
