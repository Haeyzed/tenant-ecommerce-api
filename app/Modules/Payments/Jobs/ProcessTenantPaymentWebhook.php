<?php

declare(strict_types=1);

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Payments\PaymentGatewayFactory;
use App\Shared\Payments\WebhookLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Handles one stored storefront payment webhook in its tenant (spec §15.6
 * step 5), with the same retry schedule as billing webhooks. Handling is
 * idempotent through the payment row transitions.
 */
final class ProcessTenantPaymentWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600, 14400];

    public int $timeout = 120;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $webhookLogId,
    ) {
        $this->onQueue('tenant-critical');
    }

    public function handle(PaymentGatewayFactory $factory, OrderPaymentService $payments): void
    {
        Tenant::query()->find($this->tenantId)?->run(function () use ($factory, $payments): void {
            /** @var WebhookLog|null $log */
            $log = WebhookLog::on('tenant')->find($this->webhookLogId);

            if ($log === null || $log->processed_at !== null) {
                return;
            }

            $log->forceFill(['attempts' => $log->attempts + 1])->save();

            try {
                $driver = $factory->forTenantWebhook($log->provider, $log->mode);

                if ($driver !== null) {
                    $payments->handleWebhookEvent($driver->parseWebhookEvent($log->payload, (string) json_encode($log->payload)), $log->provider, $log->mode);
                }

                $log->forceFill(['processed_at' => now(), 'error' => null])->save();
            } catch (Throwable $e) {
                $log->forceFill(['error' => mb_substr($e::class.': '.$e->getMessage(), 0, 2000)])->save();

                throw $e;
            }
        });
    }
}
