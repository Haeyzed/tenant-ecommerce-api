<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * tenants.status (spec §6.5).
 */
enum TenantStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Provisioning = 'provisioning';
    case ProvisioningFailed = 'provisioning_failed';
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
    case Purged = 'purged';

    /**
     * Statuses whose queued tenant jobs still run (spec §72.1).
     *
     * @return list<self>
     */
    public static function jobRunnable(): array
    {
        return [self::Active, self::Suspended, self::Closed];
    }
}
