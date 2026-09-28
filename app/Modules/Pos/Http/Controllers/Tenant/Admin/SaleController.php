<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Orders\Models\Order;
use App\Modules\Pos\Http\PosPresenter;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Services\PosSaleService;
use App\Modules\Pos\Services\PosSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POS sales (spec §51.3, §51.6, §51.8).
 */
final class SaleController extends Controller
{
    public function __construct(
        private readonly PosSaleService $sales,
        private readonly PosSettingsService $settings,
        private readonly PosPresenter $presenter,
        private readonly OrderPresenter $orders,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'register_id' => ['sometimes', 'integer'],
            'session_id' => ['sometimes', 'integer'],
            'cashier_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(Order::STATUSES)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'string', 'max:40'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->sales->listSales($filters)->through(fn (Order $o): array => [
            ...$this->orders->order($o, false, true),
            'pos_session_id' => $o->pos_session_id,
        ]));
    }

    /**
     * Body: register_id, lines[{product_id, variant_id?, quantity}], customer_id?, coupon_code?, reward_points?
     */
    public function quote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'register_id' => ['required', 'integer', Rule::exists('tenant.pos_registers', 'id')],
            'lines' => ['required', 'array'],
            'customer_id' => ['sometimes', 'nullable', 'integer'],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reward_points' => ['sometimes', 'integer', 'min:0'],
        ]);

        $customerId = $validated['customer_id'] ?? $this->settings->getSettings()->default_customer_id;
        $customer = $customerId === null ? null : Customer::query()->find($customerId);

        return APIResponse::success($this->sales->quote(PosRegister::query()->findOrFail($validated['register_id']), $validated['lines'], $customer,
            $validated['coupon_code'] ?? null, (int) ($validated['reward_points'] ?? 0))->toArray());
    }

    /**
     * Body: register_id, idempotency_key, lines[…], payments[{method, amount, reference?, gift_card_code?}],
     * customer_id?, coupon_code?, reward_points?, credit_sale?, quote_total?, sold_at? and
     * pos_session_id? (offline sync), cashier_user_id?, notes?
     * 201 for a new sale; 200 with the existing sale for a repeated idempotency_key (§51.6).
     */
    public function store(Request $request): JsonResponse
    {
        $registerId = $request->validate(['register_id' => ['required', 'integer', Rule::exists('tenant.pos_registers', 'id')]])['register_id'];
        $order = $this->sales->createSale(PosRegister::query()->findOrFail($registerId), $request->except('register_id'), $this->user($request));
        $body = ['sale' => $this->presenter->sale($order), 'receipt' => $this->sales->generateReceipt($order)];

        return $order->wasRecentlyCreated ? APIResponse::created($body, 'Sale completed') : APIResponse::success($body, 'Sale already recorded');
    }

    /**
     * Body: reason, terminal_reversed? (true once a card payment was reversed
     * on a terminal that has no refund API).
     */
    public function void(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'terminal_reversed' => ['sometimes', 'boolean'],
        ]);

        $order = $this->sales->voidSale($this->posSale($order), $validated['reason'], $this->user($request), (bool) ($validated['terminal_reversed'] ?? false));

        return APIResponse::success($this->presenter->sale($order->refresh()), 'Sale voided');
    }

    public function receipt(Order $order): JsonResponse
    {
        return APIResponse::success($this->sales->generateReceipt($this->posSale($order)));
    }

    private function posSale(Order $order): Order
    {
        return $order->order_source === 'pos' ? $order : throw new NotFoundHttpException;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
