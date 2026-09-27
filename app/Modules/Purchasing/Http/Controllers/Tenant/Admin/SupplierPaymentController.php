<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Purchasing\Http\PurchasingPresenter;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Services\SupplierPaymentService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Supplier payments and balances (spec §49.4, §49.7).
 */
final class SupplierPaymentController extends Controller
{
    public function __construct(
        private readonly SupplierPaymentService $payments,
        private readonly PurchasingPresenter $presenter,
        private readonly CurrencyService $currencies,
    ) {}

    public function index(Request $request, Supplier $supplier): JsonResponse
    {
        $filters = $request->validate([
            'purchase_order_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->payments->listPaymentsForSupplier($supplier, $filters)->through(fn (SupplierPayment $p): array => $this->presenter->payment($p)));
    }

    /**
     * Body: amount_paid, payment_method, purchase_order_id? (null = a bulk
     * payment in the base currency), account_id?, paid_at?, reference?, notes?, amount_received?, change_given?
     */
    public function store(Request $request, Supplier $supplier): JsonResponse
    {
        $orderId = $request->validate(['purchase_order_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.purchase_orders', 'id')]])['purchase_order_id'] ?? null;
        $payment = $this->payments->recordPayment($supplier, $request->except('purchase_order_id'), $this->user($request), $orderId === null ? null : PurchaseOrder::query()->findOrFail($orderId));

        return APIResponse::created($this->presenter->payment($this->payments->getPayment($payment)), 'Payment recorded');
    }

    public function supplierBalance(Supplier $supplier): JsonResponse
    {
        return APIResponse::success([
            'supplier_id' => $supplier->id,
            'balance' => $this->payments->getSupplierBalance($supplier),
            'currency_code' => $this->currencies->baseCurrency(),
        ]);
    }

    /**
     * Body: amount_paid?, payment_method?, account_id?, paid_at?, reference?, notes?, amount_due?, amount_received?, change_given?
     */
    public function update(Request $request, SupplierPayment $payment): JsonResponse
    {
        return APIResponse::success($this->presenter->payment($this->payments->getPayment($this->payments->updatePayment($payment, $request->all()))), 'Payment updated');
    }

    public function destroy(SupplierPayment $payment): JsonResponse
    {
        $this->payments->deletePayment($payment);

        return APIResponse::success(null, 'Payment deleted');
    }

    public function indexForPurchaseOrder(PurchaseOrder $order): JsonResponse
    {
        return APIResponse::success($this->payments->listPaymentsForPurchaseOrder($order)->map(fn (SupplierPayment $p): array => $this->presenter->payment($p))->values());
    }

    public function purchaseOrderBalance(PurchaseOrder $order): JsonResponse
    {
        return APIResponse::success([
            'purchase_order_id' => $order->id,
            'total' => $order->total(),
            'balance' => $this->payments->getBalanceForPurchaseOrder($order),
            'currency_code' => $order->currency_code,
        ]);
    }

    /**
     * Every supplier owed money, largest first (§49.6).
     */
    public function outstanding(): JsonResponse
    {
        return APIResponse::success($this->payments->getOutstandingBalances());
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
