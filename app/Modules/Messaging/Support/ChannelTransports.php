<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

use App\Modules\Messaging\Services\PushDeviceTokenService;
use App\Modules\Messaging\Services\WhatsAppSettingsService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Messaging\PushNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * Delivery rule 5 of spec §17.3: the channel's transport is configured and
 * the recipient has a route on it. Lookups are memoised per tenant for the
 * lifetime of one instance (one dispatch).
 */
final class ChannelTransports
{
    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(
        private readonly SmsGatewayFactory $sms,
        private readonly WhatsAppSettingsService $whatsapp,
        private readonly PushDeviceTokenService $pushTokens,
        private readonly FeatureAccessService $features,
    ) {}

    public function canDeliver(string $channel, NotificationScope $scope, object $recipient): bool
    {
        return match ($channel) {
            'database' => $this->hasInbox($recipient),
            'email' => filled($this->route($recipient, 'mail')),
            'sms' => filled(self::phone($recipient)) && $this->smsConfigured($scope),
            'whatsapp' => $scope === NotificationScope::Tenant && filled(self::phone($recipient)) && $this->whatsappConfigured(),
            'push' => $scope === NotificationScope::Tenant && $recipient instanceof Model
                && PushNotificationService::isConfigured() && $this->pushTokens->hasTokens($recipient),
            default => false,
        };
    }

    /**
     * The recipient's phone route for SMS and WhatsApp.
     */
    public static function phone(object $recipient): ?string
    {
        if ($recipient instanceof AnonymousNotifiable) {
            return $recipient->routes['sms'] ?? null;
        }

        if ($recipient instanceof Model) {
            if (method_exists($recipient, 'routeNotificationForSms')) {
                return $recipient->routeNotificationForSms();
            }

            $attributes = $recipient->getAttributes();

            return isset($attributes['phone']) ? (string) $attributes['phone'] : null;
        }

        return null;
    }

    private function route(object $recipient, string $channel): mixed
    {
        return method_exists($recipient, 'routeNotificationFor') ? $recipient->routeNotificationFor($channel) : null;
    }

    /**
     * In-app delivery needs a notifiable model with a notifications table.
     * Tenants have no landlord inbox yet (UD-10).
     */
    private function hasInbox(object $recipient): bool
    {
        return $recipient instanceof Model
            && ! $recipient instanceof Tenant
            && method_exists($recipient, 'notifications');
    }

    private function smsConfigured(NotificationScope $scope): bool
    {
        return $this->memo('sms:'.$scope->value, fn (): bool => $scope === NotificationScope::Tenant
            ? $this->sms->forTenant() !== null
            : $this->sms->forPlatform() !== null);
    }

    private function whatsappConfigured(): bool
    {
        return $this->memo('whatsapp', function (): bool {
            $tenant = tenant();

            return $tenant instanceof Tenant
                && $this->features->tenantCanAccess($tenant, 'whatsapp')
                && $this->whatsapp->gateway() !== null;
        });
    }

    /**
     * @param  callable(): bool  $resolve
     */
    private function memo(string $key, callable $resolve): bool
    {
        $key = (tenant()?->getTenantKey() ?? 'landlord').':'.$key;

        return $this->memo[$key] ??= $resolve();
    }
}
