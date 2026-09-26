<?php

declare(strict_types=1);

namespace App\Modules\Access\Support;

/**
 * Matches permission names against starter-set patterns such as
 * "accounting.*" or "products.view" (spec §12.3).
 */
final class PermissionPattern
{
    public static function matches(string $permission, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }

        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';

        return preg_match($regex, $permission) === 1;
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $include
     * @param  list<string>  $exclude
     * @return list<string>
     */
    public static function filter(array $permissions, array $include, array $exclude = []): array
    {
        return array_values(array_filter($permissions, static function (string $permission) use ($include, $exclude): bool {
            foreach ($exclude as $pattern) {
                if (self::matches($permission, $pattern)) {
                    return false;
                }
            }

            foreach ($include as $pattern) {
                if (self::matches($permission, $pattern)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
