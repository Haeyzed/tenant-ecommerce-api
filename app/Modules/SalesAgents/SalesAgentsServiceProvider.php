<?php

declare(strict_types=1);

namespace App\Modules\SalesAgents;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderLifecycle;
use App\Modules\SalesAgents\Services\SalesAgentCommissionService;
use Illuminate\Support\ServiceProvider;

/**
 * Sales agents on the order lifecycle (§52.2): an attributed order earns a
 * pending commission when it is confirmed, while the module is enabled.
 */
final class SalesAgentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(OrderLifecycle::class, function (OrderLifecycle $lifecycle): void {
            $lifecycle->on(OrderLifecycle::CONFIRMED, 'sales_agents', fn (Order $order) => $this->app->make(SalesAgentCommissionService::class)->calculateCommission($order));
        });
    }
}
