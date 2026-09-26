<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * Encodes and decodes typed key/value settings rows (spec §13). All settings
 * tables share the {key, value, type} shape.
 */
final class SettingValue
{
    public static function encode(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'bool' => $value ? '1' : '0',
            'int' => (string) (int) $value,
            'decimal' => (string) $value,
            'json' => (string) json_encode($value),
            'encrypted_json' => Crypt::encryptString((string) json_encode($value)),
            default => (string) $value,
        };
    }

    public static function decode(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'bool' => $value === '1',
            'int' => (int) $value,
            'decimal' => $value,
            'json' => json_decode($value, true),
            'encrypted_json' => json_decode(Crypt::decryptString($value), true),
            default => $value,
        };
    }
}
