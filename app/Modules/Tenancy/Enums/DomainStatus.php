<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

enum DomainStatus: string
{
    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Active = 'active';
    case Misconfigured = 'misconfigured';
    case Failed = 'failed';
}
