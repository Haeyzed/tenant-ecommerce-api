<?php

declare(strict_types=1);

namespace App\Shared\Payments;

use App\Modules\Billing\Models\PlatformPaymentGateway;
use App\Modules\Payments\Models\TenantPaymentSetting;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Payments\Contracts\PaymentGatewayInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only builder of payment drivers (spec §15.1, §15.3). Credentials come
 * only from stored rows of the matching domain, so platform billing can
 * never use a tenant account and tenant code can never obtain a platform
 * secret. Live drivers are refused while PAYMENTS_LIVE_ALLOWED is false.
 */
final class PaymentGatewayFactory
{
    /**
     * The platform row for the provider and mode. The row need not be
     * enabled: a disabled gateway stops new checkouts, but existing
     * subscriptions keep renewing and refunding with it (§15.10).
     */
    public function forPlatform(string $provider, string $mode): PaymentGatewayInterface
    {
        $this->assertProviderAndMode($provider, $mode);

        $row = PlatformPaymentGateway::query()->where('provider', $provider)->where('mode', $mode)->first();

        if ($row === null) {
            throw ApiException::unprocessable('gateway_not_configured', "The {$provider} {$mode} gateway is not configured.");
        }

        return $this->make(new GatewayCredentials(
            $provider,
            $mode,
            $row->secret_key,
            $row->public_key,
            $row->webhook_secret,
            (array) ($row->extra_credentials ?? []),
        ));
    }

    /**
     * The current tenant's active row for the provider and mode (default:
     * the tenant's current payment_mode).
     */
    public function forTenant(string $provider, ?string $mode = null): PaymentGatewayInterface
    {
        if (! tenancy()->initialized) {
            throw new RuntimeException('PaymentGatewayFactory::forTenant() requires the tenant context.');
        }

        $mode ??= (string) app(TenantSettingsService::class)->get('payment_mode');
        $this->assertProviderAndMode($provider, $mode);

        $row = TenantPaymentSetting::query()->where('provider', $provider)->where('mode', $mode)->where('is_active', true)->first();

        if ($row === null) {
            throw ApiException::unprocessable('gateway_not_configured', "The {$provider} {$mode} gateway is not active.");
        }

        return $this->make(new GatewayCredentials($provider, $mode, $row->secret_key, $row->public_key, $row->webhook_secret));
    }

    /**
     * A driver for credentials that are being validated before they are
     * saved. Only the credential services call this.
     */
    public function forValidation(GatewayCredentials $credentials): PaymentGatewayInterface
    {
        $this->assertProviderAndMode($credentials->provider, $credentials->mode);

        return $this->make($credentials);
    }

    /**
     * Webhook verification must work for any configured row of the URL's
     * provider and mode (§15.6 step 1), including a disabled one.
     */
    public function forPlatformWebhook(string $provider, string $mode): ?PaymentGatewayInterface
    {
        try {
            return $this->forPlatform($provider, $mode);
        } catch (ApiException|InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The current tenant's row for the URL's provider and mode, active or
     * not, so late events for a deactivated gateway still verify (§15.6).
     */
    public function forTenantWebhook(string $provider, string $mode): ?PaymentGatewayInterface
    {
        if (! tenancy()->initialized || ! array_key_exists($provider, (array) config('payments.providers')) || ! in_array($mode, ['test', 'live'], true)) {
            return null;
        }

        $row = TenantPaymentSetting::query()->where('provider', $provider)->where('mode', $mode)->first();

        return $row === null ? null : $this->make(new GatewayCredentials($provider, $mode, $row->secret_key, $row->public_key, $row->webhook_secret));
    }

    /**
     * Providers able to charge a currency (§15.3).
     *
     * @return list<string>
     */
    public function supportedProvidersForCurrency(string $currencyCode, string $context, ?string $mode = null): array
    {
        $currency = strtoupper($currencyCode);

        if ($context === 'platform') {
            return PlatformPaymentGateway::query()
                ->where('is_enabled', true)
                ->when($mode !== null, static fn ($q) => $q->where('mode', $mode))
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->get()
                ->filter(static fn (PlatformPaymentGateway $row): bool => in_array($currency, $row->supported_currencies, true))
                ->pluck('provider')
                ->unique()
                ->values()
                ->all();
        }

        return array_values(array_keys(array_filter(
            (array) config('payments.providers'),
            static fn (array $provider): bool => in_array($currency, (array) $provider['currencies'], true),
        )));
    }

    public static function liveAllowed(): bool
    {
        return (bool) config('app.payments_live_allowed');
    }

    private function make(GatewayCredentials $credentials): PaymentGatewayInterface
    {
        if ($credentials->mode === 'live' && ! self::liveAllowed()) {
            throw ApiException::forbidden('live_payments_disabled', 'Live payments are disabled in this environment.');
        }

        $class = (string) config("payments.providers.{$credentials->provider}.driver");

        return new $class($credentials);
    }

    private function assertProviderAndMode(string $provider, string $mode): void
    {
        if (! array_key_exists($provider, (array) config('payments.providers'))) {
            throw new InvalidArgumentException("Unknown payment provider [{$provider}].");
        }

        if (! in_array($mode, ['test', 'live'], true)) {
            throw new InvalidArgumentException("Unknown payment mode [{$mode}].");
        }
    }
}
