<?php

declare(strict_types=1);

namespace App\Shared\Messaging;

/**
 * Masks phone numbers for logs: personal data is not logged in full.
 */
final class PhoneMask
{
    public static function mask(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($digits) <= 4 ? '****' : str_repeat('*', strlen($digits) - 4).substr($digits, -4);
    }
}
