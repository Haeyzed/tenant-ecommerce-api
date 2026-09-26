<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues personal access tokens with exactly one ability, the actor type
 * (spec §10.2). The plain-text token is returned once and never logged.
 */
final class TokenIssuer
{
    /**
     * @return array{token: string, token_type: string, expires_at: string|null}
     */
    public static function issue(Model $actor, string $actorType, string $device = 'api'): array
    {
        if (! in_array(HasApiTokens::class, class_uses_recursive($actor), true)) {
            throw new \InvalidArgumentException($actor::class.' cannot hold API tokens.');
        }

        $minutes = config('sanctum.expiration');
        $expiresAt = $minutes === null ? null : now()->addMinutes((int) $minutes);

        /** @var NewAccessToken $token */
        $token = $actor->createToken(mb_substr($device, 0, 100), [$actorType], $expiresAt);

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }
}
