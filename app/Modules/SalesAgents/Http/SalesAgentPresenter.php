<?php

declare(strict_types=1);

namespace App\Modules\SalesAgents\Http;

use App\Modules\SalesAgents\Models\SalesAgent;
use App\Modules\SalesAgents\Models\SalesAgentCommission;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Support\Money;

final readonly class SalesAgentPresenter
{
    public function __construct(private TenantSettingsService $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function agent(SalesAgent $agent): array
    {
        return [
            'id' => $agent->id,
            'name' => $agent->name,
            'phone' => $agent->phone,
            'email' => $agent->email,
            'agent_code' => $agent->agent_code,
            'commission_rate' => $agent->commission_rate === null ? null : (string) $agent->commission_rate,
            'effective_commission_rate' => Money::normalize((string) ($agent->commission_rate ?? $this->settings->get('default_sales_agent_commission_rate', '0'))),
            'status' => $agent->status,
            'user' => $agent->user_id === null ? null : ['id' => $agent->user_id, 'name' => $agent->relationLoaded('user') ? $agent->user?->name : null],
            'created_at' => $agent->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function commission(SalesAgentCommission $c): array
    {
        return [
            'id' => $c->id,
            'sales_agent_id' => $c->sales_agent_id,
            'order' => $c->relationLoaded('order') && $c->order !== null
                ? ['id' => $c->order->id, 'order_number' => $c->order->order_number, 'order_source' => $c->order->order_source, 'total' => (string) $c->order->total, 'currency_code' => $c->order->currency_code]
                : ['id' => $c->order_id],
            'gross_amount' => (string) $c->gross_amount,
            'commission_rate_applied' => (string) $c->commission_rate_applied,
            'commission_amount' => (string) $c->commission_amount,
            'status' => $c->status,
            'earned_at' => $c->earned_at->toIso8601String(),
            'approved_at' => $c->approved_at?->toIso8601String(),
            'paid_at' => $c->paid_at?->toIso8601String(),
        ];
    }
}
