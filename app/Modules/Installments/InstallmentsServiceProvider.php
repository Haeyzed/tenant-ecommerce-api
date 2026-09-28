<?php

declare(strict_types=1);

namespace App\Modules\Installments;

use App\Modules\Installments\Services\InstallmentPlanService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderLifecycle;
use Illuminate\Support\ServiceProvider;

/**
 * Installments on the order lifecycle (§47.4): a cancelled order stops its
 * plan; payments already made stay on the order.
 */
final class InstallmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(OrderLifecycle::class, function (OrderLifecycle $lifecycle): void {
            $lifecycle->on(OrderLifecycle::CANCELLED, 'installments', fn (Order $order) => $this->app->make(InstallmentPlanService::class)->cancelForOrder($order));
        });
    }
}
