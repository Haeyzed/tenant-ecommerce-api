<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Delayed verification of a gateway payment (spec §40.2): at 15 minutes,
 * then 1 hour and 6 hours while the provider reports it pending; after 24
 * hours the row fails with verification_timeout (Assumption A-25).
 */
final class VerifyOrderPayment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Minutes after the payment was created at which each attempt runs. */
    private const array SCHEDULE = [1 => 15, 2 => 60, 3 => 360, 4 => 1440];

    private const int FINAL_ATTEMPT = 4;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 60;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $paymentId,
        public readonly int $attempt,
    ) {
        $this->onQueue('tenant-critical');
    }

    public function handle(OrderPaymentService $payments): void
    {
        Tenant::query()->find($this->tenantId)?->run(function () use ($payments): void {
            $payment = OrderPayment::query()->find($this->paymentId);

            if ($payment === null || $payment->status !== OrderPayment::PENDING) {
                return;
            }

            $payment = $payments->verifyOrderPayment($payment);

            if ($payment->status !== OrderPayment::PENDING) {
                return;
            }

            // The attempt at 24 hours is the last: still pending means failed.
            if ($this->attempt >= self::FINAL_ATTEMPT) {
                $payments->completeGatewayPayment($payment, ['status' => 'failed', 'failure_reason' => 'verification_timeout']);

                return;
            }

            $next = $this->attempt + 1;
            self::dispatch($this->tenantId, $this->paymentId, $next)->delay($payment->created_at->copy()->addMinutes(self::SCHEDULE[$next]));
        });
    }
}
