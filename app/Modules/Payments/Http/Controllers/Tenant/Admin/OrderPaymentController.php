<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Payments\Models\OrderPayment;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * GET /api/admin/order-payments (spec §40.7): every payment, refund and
 * chargeback row across orders.
 */
final class OrderPaymentController extends Controller
{
    public function index(Request $request, OrderPresenter $presenter): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['sometimes', Rule::in([OrderPayment::PAYMENT, OrderPayment::REFUND, OrderPayment::CHARGEBACK])],
            'status' => ['sometimes', Rule::in([OrderPayment::PENDING, OrderPayment::SUCCESSFUL, OrderPayment::FAILED])],
            'payment_method' => ['sometimes', Rule::in(OrderPayment::METHODS)],
            'provider' => ['sometimes', 'string', 'max:32'],
            'mode' => ['sometimes', Rule::in(['test', 'live'])],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = OrderPayment::query()->with('recorder:id,name')
            ->when($filters['kind'] ?? null, static fn ($q, $v) => $q->where('kind', $v))
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['payment_method'] ?? null, static fn ($q, $v) => $q->where('payment_method', $v))
            ->when($filters['provider'] ?? null, static fn ($q, $v) => $q->where('provider', $v))
            ->when($filters['mode'] ?? null, static fn ($q, $v) => $q->where('mode', $v))
            ->when($filters['from'] ?? null, static fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, static fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success($page->through(static fn (OrderPayment $p): array => $presenter->payment($p)));
    }
}
