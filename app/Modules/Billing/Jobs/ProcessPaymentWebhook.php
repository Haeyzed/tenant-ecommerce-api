<?php

declare(strict_types=1);

namespace App\Modules\Billing\Jobs;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Shared\Payments\PaymentGatewayFactory;
use App\Shared\Payments\WebhookLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Handles one stored landlord billing webhook (spec §15.6 step 5): 5
 * retries with 1, 5, 15, 60 and 240 minute backoff. Business handling is
 * idempotent through the row transitions.
 */
final class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600, 14400];

    public int $timeout = 120;

    public function __construct(public readonly int $webhookLogId)
    {
        $this->onQueue('landlord-default');
    }

    public function handle(PaymentGatewayFactory $factory, SubscriptionService $subscriptions): void
    {
        $log = WebhookLog::landlord()->find($this->webhookLogId);

        if ($log === null || $log->processed_at !== null) {
            return;
        }

        $log->forceFill(['attempts' => $log->attempts + 1])->save();

        try {
            $driver = $factory->forPlatform($log->provider, $log->mode);
            $event = $driver->parseWebhookEvent($log->payload, (string) json_encode($log->payload));

            $subscriptions->handleWebhookEvent($event, $log->provider, $log->mode);

            $log->forceFill(['processed_at' => now(), 'error' => null])->save();
        } catch (Throwable $e) {
            $log->forceFill(['error' => mb_substr($e::class.': '.$e->getMessage(), 0, 2000)])->save();

            throw $e;
        }
    }

    /**
     * Retries exhausted: alert super-admins and billing admins, at most
     * once per provider per hour.
     */
    public function failed(?Throwable $exception): void
    {
        $log = WebhookLog::landlord()->find($this->webhookLogId);

        if ($log === null || ! Cache::store('landlord')->add('webhook-failure-alert:'.$log->provider, true, 3600)) {
            return;
        }

        $recipients = PlatformUser::query()
            ->withPlatformRole(['super-admin', 'billing-admin'])
            ->where('is_active', true)
            ->get();

        app(NotificationDispatchService::class)->dispatch('platform.webhook_failures', $recipients, [
            'provider' => $log->provider,
            'event_type' => (string) $log->event_type,
            'reference' => (string) $log->provider_reference,
        ]);
    }
}
