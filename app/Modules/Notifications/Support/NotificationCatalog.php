<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationScope;
use InvalidArgumentException;

/**
 * The only reader of config/notifications/{landlord,tenant}.php (spec
 * §17.4). Keys are unique across both scopes, so a key alone identifies the
 * database its template lives in.
 */
final class NotificationCatalog
{
    /**
     * @return array<string, array{audience: list<string>, channels: array<string, bool>, subject: string, body: string, mandatory: bool}>
     */
    public function definitions(NotificationScope $scope): array
    {
        return (array) config('notifications.'.$scope->value, []);
    }

    public function has(string $key, NotificationScope $scope): bool
    {
        return isset($this->definitions($scope)[$key]);
    }

    public function scopeOf(string $key): NotificationScope
    {
        foreach (NotificationScope::cases() as $scope) {
            if ($this->has($key, $scope)) {
                return $scope;
            }
        }

        throw new InvalidArgumentException("Unknown notification key [{$key}].");
    }

    /**
     * @return array{audience: list<string>, channels: array<string, bool>, subject: string, body: string, mandatory: bool}
     */
    public function definition(string $key, NotificationScope $scope): array
    {
        return $this->definitions($scope)[$key]
            ?? throw new InvalidArgumentException("Unknown {$scope->value} notification key [{$key}].");
    }

    /**
     * The default matrix for every channel: unlisted channels are off.
     *
     * @return array<string, bool>
     */
    public function defaultChannels(string $key, NotificationScope $scope): array
    {
        $configured = $this->definition($key, $scope)['channels'];
        $matrix = [];

        foreach (NotificationChannel::values() as $channel) {
            $matrix[$channel] = (bool) ($configured[$channel] ?? false);
        }

        return $matrix;
    }

    /**
     * Placeholders documented by a key's default content (spec §17.8).
     *
     * @return list<string>
     */
    public function variables(string $key, NotificationScope $scope): array
    {
        $definition = $this->definition($key, $scope);

        preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/', $definition['subject'].' '.$definition['body'], $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Configuration problems, for the architecture tests.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $landlord = array_keys($this->definitions(NotificationScope::Landlord));
        $tenant = array_keys($this->definitions(NotificationScope::Tenant));

        foreach (array_intersect($landlord, $tenant) as $key) {
            $problems[] = "[{$key}] is defined in both scopes.";
        }

        foreach (NotificationScope::cases() as $scope) {
            foreach ($this->definitions($scope) as $key => $definition) {
                foreach ($definition['audience'] as $audience) {
                    if (! in_array($audience, $scope->audiences(), true)) {
                        $problems[] = "[{$key}] has audience [{$audience}], invalid in the {$scope->value} scope.";
                    }
                }

                foreach (array_keys($definition['channels']) as $channel) {
                    if (NotificationChannel::tryFrom($channel) === null) {
                        $problems[] = "[{$key}] has unknown channel [{$channel}].";
                    }
                }

                if (! in_array(true, $definition['channels'], true)) {
                    $problems[] = "[{$key}] has no enabled channel.";
                }
            }
        }

        return $problems;
    }
}
