<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Jobs\ProcessPaymentWebhook;
use App\Modules\Billing\Models\PlatformPaymentGateway;
use App\Shared\Http\Middleware\Shared\VerifyWebhookSignature;
use App\Shared\Payments\Contracts\PaymentGatewayInterface;
use App\Shared\Payments\WebhookLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Landlord billing webhooks (spec §15.6 steps 2–4): mode check, storage in
 * the landlord webhook_logs, deduplication, and a queued processing job.
 * Always answers 200 once the signature is valid.
 */
final class BillingWebhookController extends Controller
{
    public function handle(Request $request, string $provider, string $mode): JsonResponse
    {
        /** @var PaymentGatewayInterface $driver */
        $driver = $request->attributes->get(VerifyWebhookSignature::DRIVER_ATTRIBUTE);
        $raw = $request->getContent();
        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['received' => true, 'ignored' => 'unparseable']);
        }

        $event = $driver->parseWebhookEvent($payload, $raw);
        $modeMismatch = $event->livemode !== null && $event->livemode !== ($mode === 'live');

        try {
            $log = DB::connection('landlord')->transaction(function () use ($provider, $mode, $event, $payload, $modeMismatch): WebhookLog {
                /** @var WebhookLog $log */
                $log = WebhookLog::landlord()->create([
                    'provider' => $provider,
                    'mode' => $mode,
                    'provider_event_id' => mb_substr($event->eventId, 0, 255),
                    'event_type' => $event->type,
                    'provider_reference' => $event->reference ?? $event->providerReference,
                    'payload' => $payload,
                    'received_at' => now(),
                    'error' => $modeMismatch ? 'mode_mismatch' : null,
                ]);

                PlatformPaymentGateway::query()->where('provider', $provider)->where('mode', $mode)->update(['last_webhook_at' => now()]);

                if (! $modeMismatch) {
                    ProcessPaymentWebhook::dispatch($log->id)->afterCommit();
                }

                return $log;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        return response()->json(['received' => true, 'id' => $log->id]);
    }
}
