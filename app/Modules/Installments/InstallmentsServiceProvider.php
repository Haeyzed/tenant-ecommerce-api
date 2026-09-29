<?php

declare(strict_types=1);

namespace App\Modules\Installments;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Services\InstallmentPlanService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderLifecycle;
use Illuminate\Support\ServiceProvider;

/**
 * Installments on the order lifecycle (§47.4): a cancelled order stops its
 * plan; payments already made stay on the order. Personal data (§26.4):
 * an anonymised customer's saved payment authorisations are deleted; the
 * plans stay, and remaining installments are paid manually.
 */
final class InstallmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(OrderLifecycle::class, function (OrderLifecycle $lifecycle): void {
            $lifecycle->on(OrderLifecycle::CANCELLED, 'installments', fn (Order $order) => $this->app->make(InstallmentPlanService::class)->cancelForOrder($order));
        });

        $this->app->afterResolving(CustomerPrivacyRegistry::class, static function (CustomerPrivacyRegistry $privacy): void {
            $privacy->registerEraser('installment_authorizations', static fn (Customer $customer) => InstallmentPlan::query()
                ->whereIn('order_id', Order::query()->where('customer_id', $customer->id)->select('id'))
                ->update(['authorization_token' => null, 'updated_at' => now()]));
        });
    }
}
