<?php

declare(strict_types=1);

namespace App\Modules\Returns\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Returns\Http\ReturnPresenter;
use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Returns\Services\ExchangeService;
use App\Modules\Returns\Services\ReturnService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Return administration (spec §41.7).
 */
final class ReturnController extends Controller
{
    public function __construct(
        private readonly ReturnService $returns,
        private readonly ReturnPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(OrderReturn::STATUSES)],
            'reason_id' => ['sometimes', 'integer'],
            'resolution_type' => ['sometimes', Rule::in(['refund', 'exchange'])],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->returns->listReturns($filters)->through(fn (OrderReturn $r): array => $this->presenter->return($r, true)));
    }

    public function show(OrderReturn $return): JsonResponse
    {
        return APIResponse::success($this->present($return));
    }

    public function approve(Request $request, OrderReturn $return): JsonResponse
    {
        $validated = $request->validate(['requires_physical_return' => ['sometimes', 'boolean']]);
        $physical = array_key_exists('requires_physical_return', $validated) ? $request->boolean('requires_physical_return') : null;

        return APIResponse::success($this->present($this->returns->approveReturn($return, $physical)), 'Return approved');
    }

    public function reject(Request $request, OrderReturn $return): JsonResponse
    {
        return APIResponse::success($this->present($this->returns->rejectReturn($return, (string) $request->input('reason', ''))), 'Return rejected');
    }

    public function receive(Request $request, OrderReturn $return): JsonResponse
    {
        return APIResponse::success($this->present($this->returns->markReceived($return, (array) $request->input('items', []))), 'Return received');
    }

    public function refund(Request $request, OrderReturn $return): JsonResponse
    {
        $validated = $request->validate(['amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'decimal:0,4']]);

        return APIResponse::success($this->present($this->returns->processRefund($return, isset($validated['amount']) ? (string) $validated['amount'] : null,
            $this->actor($request), $request->attributes->get('idempotency_key'))), 'Refund processed');
    }

    public function exchange(Request $request, OrderReturn $return, ExchangeService $exchanges): JsonResponse
    {
        $replacement = $exchanges->processExchange($return, $this->actor($request));

        return APIResponse::success([...$this->present($return->refresh()), 'replacement_order' => [
            'id' => $replacement->id, 'order_number' => $replacement->order_number, 'total' => (string) $replacement->total, 'payment_status' => $replacement->payment_status,
        ]], 'Exchange processed');
    }

    public function close(OrderReturn $return): JsonResponse
    {
        return APIResponse::success($this->present($this->returns->closeReturn($return)), 'Return closed');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrderReturn $return): array
    {
        return $this->presenter->return($return->load(['items.orderItem', 'reason', 'order']), true);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
