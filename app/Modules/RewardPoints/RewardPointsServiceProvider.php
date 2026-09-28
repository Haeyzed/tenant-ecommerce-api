<?php

declare(strict_types=1);

namespace App\Modules\RewardPoints;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderLifecycle;
use App\Modules\RewardPoints\Services\RewardPointService;
use Illuminate\Support\ServiceProvider;

/**
 * Reward points on the order lifecycle (§54.2): earned when an order
 * completes (while the programme is active), restored when it is cancelled.
 */
final class RewardPointsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(OrderLifecycle::class, function (OrderLifecycle $lifecycle): void {
            $lifecycle->on(OrderLifecycle::COMPLETED, 'reward_points', fn (Order $order) => $this->app->make(RewardPointService::class)->earnPoints($order));
            $lifecycle->on(OrderLifecycle::CANCELLED, 'reward_points', fn (Order $order) => $this->app->make(RewardPointService::class)->restorePoints($order));
        });
    }
}
