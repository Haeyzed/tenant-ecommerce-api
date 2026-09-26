<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Jobs\ProcessTenantPaymentWebhook;
use App\Modules\Payments\Models\TenantPaymentSetting;
use App\Shared\Http\Middleware\Shared\VerifyWebhookSignature;
use App\Shared\Payments\Contracts\PaymentGatewayInterface;
use App\Shared\Payments\WebhookLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Storefront payment webhooks (spec §15.6 steps 2–4): mode check, storage
 * in the tenant's own webhook_logs, deduplication by provider event id and
 * a queued processing job. Always 200 once the signature is valid.
 */
final class TenantPaymentWebhookController extends Controller
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
        $tenantId = (string) tenant()?->getTenantKey();

        try {
            $log = DB::connection('tenant')->transaction(static function () use ($provider, $mode, $event, $payload, $modeMismatch, $tenantId): WebhookLog {
                /** @var WebhookLog $log */
                $log = WebhookLog::on('tenant')->create([
                    'provider' => $provider,
                    'mode' => $mode,
                    'provider_event_id' => mb_substr($event->eventId, 0, 255),
                    'event_type' => $event->type,
                    'provider_reference' => $event->reference ?? $event->providerReference,
                    'payload' => $payload,
                    'received_at' => now(),
                    'error' => $modeMismatch ? 'mode_mismatch' : null,
                ]);

                TenantPaymentSetting::query()->where('provider', $provider)->where('mode', $mode)->update(['updated_at' => now()]);

                if (! $modeMismatch) {
                    ProcessTenantPaymentWebhook::dispatch($tenantId, $log->id)->afterCommit();
                }

                return $log;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        return response()->json(['received' => true, 'id' => $log->id]);
    }
}
