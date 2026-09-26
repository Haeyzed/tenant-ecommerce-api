<?php

declare(strict_types=1);

namespace App\Modules\Orders\Jobs;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Cancels an unpaid online order at payment_expires_at (spec §39.5,
 * §72.3), releasing its stock, flash-sale claims and promotion
 * reservations. A gateway payment still in flight is verified first; while
 * the provider reports it pending, the job re-checks in 15 minutes.
 */
final class ExpireUnpaidOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    /** Re-checks while a payment is in flight (15 minutes apart, ~25 hours). */
    private const int MAX_RECHECKS = 100;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $orderId,
        public readonly int $recheck = 0,
    ) {
        $this->onQueue('tenant-critical');
    }

    public function handle(): void
    {
        Tenant::query()->find($this->tenantId)?->run(function (): void {
            $order = Order::query()->find($this->orderId);

            if ($order === null) {
                return;
            }

            $orders = app(OrderService::class);

            if ($orders->expireUnpaidOrder($order) === 'pending') {
                app(OrderPaymentService::class)->verifyPendingForOrder($order);

                if ($orders->expireUnpaidOrder($order->refresh()) === 'pending' && $this->recheck < self::MAX_RECHECKS) {
                    self::dispatch($this->tenantId, $this->orderId, $this->recheck + 1)->delay(now()->addMinutes(15));
                }
            }
        });
    }
}
