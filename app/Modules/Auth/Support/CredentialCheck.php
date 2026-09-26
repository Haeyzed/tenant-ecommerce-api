<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Password verification with the same cost whether or not the account
 * exists, so response timing does not disclose registered emails.
 */
final class CredentialCheck
{
    public static function assert(?Authenticatable $user, string $password): void
    {
        $hash = $user?->getAuthPassword();

        $valid = is_string($hash) && $hash !== ''
            ? Hash::check($password, $hash)
            : Hash::check($password, self::dummyHash()) && false;

        if (! $valid) {
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('timing-equaliser');
    }
}
