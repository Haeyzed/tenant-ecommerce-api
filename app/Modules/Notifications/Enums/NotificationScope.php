<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * The two notification scopes (spec §17.1). Both use the same table shapes,
 * each in its own database.
 */
enum NotificationScope: string
{
    case Landlord = 'landlord';
    case Tenant = 'tenant';

    /**
     * The scope of the current execution context: template and matrix
     * routes act on the database of the context they run in.
     */
    public static function current(): self
    {
        return tenancy()->initialized ? self::Tenant : self::Landlord;
    }

    public function connection(): string
    {
        return $this->value;
    }

    /**
     * Audience values valid in this scope (spec §17.2).
     *
     * @return list<string>
     */
    public function audiences(): array
    {
        return match ($this) {
            self::Landlord => ['tenant', 'platform_user', 'affiliate', 'registrant'],
            self::Tenant => ['admin', 'customer', 'supplier', 'seller', 'sales_agent'],
        };
    }

    public function queue(): string
    {
        return match ($this) {
            self::Landlord => 'landlord-default',
            self::Tenant => 'tenant-default',
        };
    }
}
