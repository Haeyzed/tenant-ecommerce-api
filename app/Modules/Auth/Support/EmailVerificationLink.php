<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use Illuminate\Support\Carbon;

/**
 * Stateless email-verification parameters: {id, hash, expires, signature}.
 * hash binds the link to the current email address; the HMAC signature
 * (keyed by APP_KEY) prevents forging and expires with the link. The
 * frontend posts them back to the verify route.
 */
final class EmailVerificationLink
{
    private const int LIFETIME_MINUTES = 1440;

    /**
     * @return array{id: string, hash: string, expires: int, signature: string}
     */
    public static function parameters(string $actor, int|string $id, string $email): array
    {
        $hash = sha1(strtolower($email));
        $expires = now()->addMinutes(self::LIFETIME_MINUTES)->getTimestamp();

        return [
            'id' => (string) $id,
            'hash' => $hash,
            'expires' => $expires,
            'signature' => self::sign($actor, (string) $id, $hash, $expires),
        ];
    }

    public static function isValid(string $actor, string $id, string $hash, int $expires, string $signature, string $currentEmail): bool
    {
        return Carbon::createFromTimestamp($expires)->isFuture()
            && hash_equals(sha1(strtolower($currentEmail)), $hash)
            && hash_equals(self::sign($actor, $id, $hash, $expires), $signature);
    }

    private static function sign(string $actor, string $id, string $hash, int $expires): string
    {
        return hash_hmac('sha256', implode('|', [$actor, $id, $hash, $expires]), (string) config('app.key'));
    }
}
