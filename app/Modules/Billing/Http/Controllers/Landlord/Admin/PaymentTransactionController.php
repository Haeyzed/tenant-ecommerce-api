<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Http\Resources\PaymentTransactionResource;
use App\Modules\Billing\Metrics\PaymentMetrics;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Dashboard\Services\Landlord\PlatformDashboardService;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Requests\MetricsRangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PaymentTransactionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * The list screen's KPI strip (spec §22.4).
     */
    public function metrics(MetricsRangeRequest $request, PlatformDashboardService $dashboard, PaymentMetrics $metrics): JsonResponse
    {
        $result = $dashboard->contextualKpis('payment-transactions', $dashboard->range($request->rangeInput()), $metrics->contextual(...));

        return APIResponse::success($result['data'], meta: ['generated_at' => $result['generated_at'], 'cached' => $result['cached']]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['sometimes', Rule::in(['charge', 'authorization', 'refund', 'chargeback'])],
            'status' => ['sometimes', Rule::in(['pending', 'successful', 'failed'])],
            'mode' => ['sometimes', 'in:test,live'],
            'provider' => ['sometimes', Rule::in(['flutterwave', 'paystack', 'stripe'])],
            'tenant' => ['sometimes', 'string', 'max:255'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = PaymentTransaction::query()
            ->when($filters['type'] ?? null, static fn ($q, $v) => $q->where('type', $v))
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['mode'] ?? null, static fn ($q, $v) => $q->where('mode', $v))
            ->when($filters['provider'] ?? null, static fn ($q, $v) => $q->where('provider', $v))
            ->when($filters['tenant'] ?? null, static fn ($q, $v) => $q->where('tenant_id', $v))
            ->when($filters['from'] ?? null, static fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, static fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return APIResponse::success(PaymentTransactionResource::collection($page));
    }

    public function show(PaymentTransaction $transaction): JsonResponse
    {
        return APIResponse::success((new PaymentTransactionResource($transaction))->withProviderData());
    }

    /**
     * Idempotency-Key is required on this route (§14.6).
     */
    public function refund(Request $request, PaymentTransaction $transaction): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        /** @var PlatformUser $user */
        $user = $request->user();
        $refund = $this->subscriptions->refundTransaction(
            $transaction,
            isset($validated['amount']) ? (string) $validated['amount'] : null,
            $validated['reason'],
            $user,
        );

        return APIResponse::created((new PaymentTransactionResource($refund))->withProviderData(), 'Refund recorded');
    }
}
