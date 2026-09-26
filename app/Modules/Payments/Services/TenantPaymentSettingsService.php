<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Payments\Models\TenantPaymentSetting;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Payments\GatewayCredentials;
use App\Shared\Payments\PaymentGatewayFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The tenant's own gateway credentials and payment mode (spec §15.4,
 * §15.9). Tenant context only.
 */
final readonly class TenantPaymentSettingsService
{
    public const array PROVIDERS = ['flutterwave', 'paystack', 'stripe'];

    public function __construct(
        private PaymentGatewayFactory $factory,
        private TenantSettingsService $settings,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * Both modes of every provider, secrets masked.
     *
     * @return list<array<string, mixed>>
     */
    public function listGateways(): array
    {
        $rows = TenantPaymentSetting::query()->get()->keyBy(static fn (TenantPaymentSetting $r): string => $r->provider.':'.$r->mode);
        $result = [];

        foreach (self::PROVIDERS as $provider) {
            foreach (['test', 'live'] as $mode) {
                $row = $rows->get($provider.':'.$mode);

                $result[] = [
                    'provider' => $provider,
                    'mode' => $mode,
                    'configured' => $row !== null,
                    'public_key' => $row?->maskedPublicKey(),
                    'has_secret_key' => $row !== null,
                    'has_webhook_secret' => $row !== null && filled($row->webhook_secret),
                    'is_active' => (bool) $row?->is_active,
                    'enabled_for_online' => $row?->enabled_for_online ?? true,
                    'credentials_verified_at' => $row?->credentials_verified_at?->toIso8601String(),
                    'webhook_url' => $this->webhookUrl($provider, $mode),
                ];
            }
        }

        return $result;
    }

    /**
     * @return Collection<int, TenantPaymentSetting>
     */
    public function getActiveGateways(?string $mode = null): Collection
    {
        return TenantPaymentSetting::query()
            ->where('mode', $mode ?? $this->currentMode())
            ->where('is_active', true)
            ->orderBy('provider')
            ->get()
            ->toBase();
    }

    /**
     * @return Collection<int, TenantPaymentSetting>
     */
    public function getOnlineGateways(): Collection
    {
        return $this->getActiveGateways()->filter(static fn (TenantPaymentSetting $r): bool => $r->enabled_for_online)->values();
    }

    /**
     * Validates the keys with the provider before saving; keys of the other
     * mode are rejected (payment_mode_mismatch). An omitted secret keeps
     * the stored one.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function saveGatewayCredentials(string $provider, string $mode, array $credentials): TenantPaymentSetting
    {
        $this->assertKnown($provider, $mode);

        if ($mode === 'live' && ! PaymentGatewayFactory::liveAllowed()) {
            throw ApiException::forbidden('live_payments_disabled', 'Live credentials cannot be saved in this environment.');
        }

        $row = TenantPaymentSetting::query()->where('provider', $provider)->where('mode', $mode)->first();

        /** @var array<string, string|null> $validated */
        $validated = validator($credentials, [
            'public_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'secret_key' => [$row === null ? 'required' : 'sometimes', 'string', 'max:1024'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ])->validate();

        $row ??= new TenantPaymentSetting(['provider' => $provider, 'mode' => $mode, 'is_active' => false, 'enabled_for_online' => true]);
        $row->fill($validated);

        $result = $this->factory->forValidation(new GatewayCredentials($provider, $mode, $row->secret_key, $row->public_key, $row->webhook_secret))
            ->validateCredentials();

        if ($result['detected_mode'] !== null && $result['detected_mode'] !== $mode) {
            throw ApiException::unprocessable('payment_mode_mismatch', "These are {$result['detected_mode']} credentials; save them in the {$result['detected_mode']} slot.", [
                'detected_mode' => $result['detected_mode'],
            ]);
        }

        if (! $result['valid']) {
            throw ApiException::unprocessable('gateway_credentials_invalid', $result['message']);
        }

        $row->credentials_verified_at = now();
        $row->save();

        ActivityRecorder::tenant('payment_settings', "Credentials saved for {$provider} ({$mode})", $row);

        return $row;
    }

    /**
     * @return array{valid: bool, detected_mode: string|null, message: string}
     */
    public function testGateway(string $provider, string $mode): array
    {
        $row = $this->find($provider, $mode);
        $result = $this->factory->forValidation(new GatewayCredentials($provider, $mode, $row->secret_key, $row->public_key, $row->webhook_secret))
            ->validateCredentials();

        if ($result['valid'] && ($result['detected_mode'] === null || $result['detected_mode'] === $mode)) {
            $row->forceFill(['credentials_verified_at' => now()])->save();
        }

        return $result;
    }

    public function activateGateway(string $provider, string $mode): TenantPaymentSetting
    {
        $row = $this->find($provider, $mode);

        if ($row->credentials_verified_at === null) {
            throw ApiException::unprocessable('gateway_not_verified', 'Validate the credentials before activating the gateway.');
        }

        $row->forceFill(['is_active' => true])->save();
        ActivityRecorder::tenant('payment_settings', "Gateway {$provider} ({$mode}) activated", $row);

        return $row;
    }

    public function deactivateGateway(string $provider, string $mode): TenantPaymentSetting
    {
        $row = $this->find($provider, $mode);
        $row->forceFill(['is_active' => false])->save();
        ActivityRecorder::tenant('payment_settings', "Gateway {$provider} ({$mode}) deactivated", $row);

        return $row;
    }

    public function setOnlineAvailability(string $provider, string $mode, bool $enabled): TenantPaymentSetting
    {
        $row = $this->find($provider, $mode);
        $row->forceFill(['enabled_for_online' => $enabled])->save();

        return $row;
    }

    /**
     * Live requires an active validated live row and PAYMENTS_LIVE_ALLOWED;
     * test is always allowed. Forward-only: existing orders keep their mode.
     */
    public function setPaymentMode(string $mode, User $by): void
    {
        if (! in_array($mode, ['test', 'live'], true)) {
            throw ApiException::unprocessable('validation_failed', 'Unknown payment mode.');
        }

        $previous = $this->currentMode();

        if ($previous === $mode) {
            return;
        }

        if ($mode === 'live') {
            if (! PaymentGatewayFactory::liveAllowed()) {
                throw ApiException::forbidden('live_payments_disabled', 'Live payments are disabled in this environment.');
            }

            if (! TenantPaymentSetting::query()->where('mode', 'live')->where('is_active', true)->whereNotNull('credentials_verified_at')->exists()) {
                throw ApiException::unprocessable('no_live_gateway', 'Activate at least one validated live gateway first.');
            }
        }

        DB::connection('tenant')->transaction(function () use ($mode): void {
            $this->settings->set('payment_mode', $mode);
        });

        ActivityRecorder::tenant('payment_settings', "Payment mode changed to {$mode}", null, ['previous_mode' => $previous, 'mode' => $mode], $by);

        $this->notifications->dispatch('payments.mode_changed', null, [
            'mode' => $mode,
            'previous_mode' => $previous,
            'changed_by' => $by->name,
        ]);
    }

    public function currentMode(): string
    {
        return (string) $this->settings->get('payment_mode');
    }

    /**
     * The per-tenant webhook URL on the landlord domain (§15.6).
     */
    public function webhookUrl(string $provider, string $mode): string
    {
        return rtrim((string) config('app.url'), '/').'/api/webhooks/'.tenant()?->getTenantKey()."/{$provider}/{$mode}";
    }

    private function find(string $provider, string $mode): TenantPaymentSetting
    {
        $this->assertKnown($provider, $mode);

        return TenantPaymentSetting::query()->where('provider', $provider)->where('mode', $mode)->firstOrFail();
    }

    private function assertKnown(string $provider, string $mode): void
    {
        if (! in_array($provider, self::PROVIDERS, true) || ! in_array($mode, ['test', 'live'], true)) {
            abort(404);
        }
    }
}
