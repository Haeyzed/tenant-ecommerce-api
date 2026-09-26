<?php

declare(strict_types=1);

namespace App\Shared\Support;

use RuntimeException;

/**
 * Every tenant channel name is prefixed with "tenant.{tenant_id}." (spec
 * §72.4), so names can never collide across tenants. Used by every tenant
 * broadcast event and by routes/channels.php.
 */
final class TenantChannel
{
    public static function name(string $suffix, ?string $tenantId = null): string
    {
        $tenantId ??= tenant()?->getTenantKey();

        if ($tenantId === null || $tenantId === '') {
            throw new RuntimeException('A tenant channel needs a tenant context.');
        }

        return 'tenant.'.$tenantId.'.'.$suffix;
    }

    /**
     * The pattern for routes/channels.php, e.g. pattern('support-conversation.{id}').
     */
    public static function pattern(string $suffix): string
    {
        return 'tenant.{tenantId}.'.$suffix;
    }
}
