<?php

declare(strict_types=1);

namespace App\Modules\SalesAgents\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\SalesAgents\Http\SalesAgentPresenter;
use App\Modules\SalesAgents\Models\SalesAgent;
use App\Modules\SalesAgents\Services\SalesAgentService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sales agents (spec §52.3) and staff attribution of an order (§52.2).
 */
final class SalesAgentController extends Controller
{
    public function __construct(
        private readonly SalesAgentService $agents,
        private readonly SalesAgentPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([SalesAgent::ACTIVE, SalesAgent::INACTIVE])],
            'search' => ['sometimes', 'string', 'max:120'],
        ]);

        return APIResponse::success($this->agents->listAgents($filters)->map(fn (SalesAgent $a): array => $this->presenter->agent($a))->values());
    }

    /**
     * Body: name, phone, email?, agent_code? (generated when absent), commission_rate?, user_id?
     */
    public function store(Request $request): JsonResponse
    {
        $agent = $this->agents->createAgent($request->only(['name', 'phone', 'email', 'agent_code', 'commission_rate', 'user_id']));

        return APIResponse::created($this->presenter->agent($agent->load('user')), 'Sales agent created');
    }

    /**
     * Body: name?, phone?, email?, agent_code?, commission_rate?, user_id?, status?
     */
    public function update(Request $request, SalesAgent $agent): JsonResponse
    {
        $agent = $this->agents->updateAgent($agent, $request->only(['name', 'phone', 'email', 'agent_code', 'commission_rate', 'user_id', 'status']));

        return APIResponse::success($this->presenter->agent($agent->load('user')), 'Sales agent updated');
    }

    public function destroy(SalesAgent $agent): JsonResponse
    {
        $this->agents->deactivateAgent($agent);

        return APIResponse::success($this->presenter->agent($agent->refresh()->load('user')), 'Sales agent deactivated');
    }

    /**
     * Body: sales_agent_id (null clears). Only before the order is confirmed.
     */
    public function attribute(Request $request, Order $order): JsonResponse
    {
        $id = $request->validate(['sales_agent_id' => ['present', 'nullable', 'integer', Rule::exists('tenant.sales_agents', 'id')]])['sales_agent_id'];
        $order = $this->agents->attributeOrder($order, $id === null ? null : SalesAgent::query()->findOrFail($id));

        return APIResponse::success(['order_id' => $order->id, 'sales_agent_id' => $order->sales_agent_id], 'Sales agent set');
    }
}
