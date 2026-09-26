<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

/**
 * Which CMS a request works on (spec §24.1): the current tenant's when
 * tenancy is initialised, the landlord website's otherwise. Landlord CMS
 * routes exist only on landlord domains and tenant routes only on tenant
 * domains, so a request can only reach its own database.
 */
final class CmsScope
{
    public const string LANDLORD = 'landlord';

    public const string TENANT = 'tenant';

    public static function current(): string
    {
        return tenancy()->initialized ? self::TENANT : self::LANDLORD;
    }

    public static function isTenant(): bool
    {
        return self::current() === self::TENANT;
    }
}
