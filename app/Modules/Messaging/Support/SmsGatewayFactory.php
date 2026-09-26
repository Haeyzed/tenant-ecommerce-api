<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Modules\Messaging\Models\SmsGatewaySetting;
use App\Shared\Messaging\Contracts\SmsGatewayInterface;
use App\Shared\Messaging\Sms\AfricasTalkingGateway;
use App\Shared\Messaging\Sms\TermiiGateway;
use InvalidArgumentException;

/**
 * Resolves SMS drivers (spec §16.3): the tenant's configured gateways, or
 * the platform's own from the environment.
 */
final class SmsGatewayFactory
{
    /**
     * The named active gateway, or the tenant's default. Null when the
     * tenant has none configured.
     */
    public function forTenant(?string $provider = null): ?SmsGatewayInterface
    {
        $setting = SmsGatewaySetting::query()
            ->where('is_active', true)
            ->when($provider !== null, static fn ($q) => $q->where('provider', $provider), static fn ($q) => $q->where('is_default', true))
            ->first();

        return $setting === null ? null : $this->make($setting->provider, $setting->credentials);
    }

    /**
     * The platform gateway from the environment; null when not configured.
     */
    public function forPlatform(): ?SmsGatewayInterface
    {
        $provider = config('services.sms.platform_provider');

        if (blank($provider)) {
            return null;
        }

        $credentials = (array) config('services.'.$provider, []);

        return $this->isComplete((string) $provider, $credentials) ? $this->make((string) $provider, $credentials) : null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function make(string $provider, array $credentials): SmsGatewayInterface
    {
        $timeout = (int) config('services.sms.timeout', 10);

        return match ($provider) {
            'termii' => new TermiiGateway(
                (string) $credentials['api_key'],
                (string) $credentials['sender_id'],
                (string) config('services.termii.base_url'),
                $timeout,
            ),
            'africas_talking' => new AfricasTalkingGateway(
                (string) $credentials['username'],
                (string) $credentials['api_key'],
                filled($credentials['sender_id'] ?? null) ? (string) $credentials['sender_id'] : null,
                (string) config('services.africas_talking.base_url'),
                $timeout,
            ),
            default => throw new InvalidArgumentException("Unknown SMS provider [{$provider}]."),
        };
    }

    /**
     * Credential keys each provider requires.
     *
     * @return list<string>
     */
    public static function requiredCredentials(string $provider): array
    {
        return match ($provider) {
            'termii' => ['api_key', 'sender_id'],
            'africas_talking' => ['username', 'api_key'],
            default => throw new InvalidArgumentException("Unknown SMS provider [{$provider}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function isComplete(string $provider, array $credentials): bool
    {
        foreach (self::requiredCredentials($provider) as $key) {
            if (blank($credentials[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
