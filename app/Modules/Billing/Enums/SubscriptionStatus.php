<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * subscriptions.status (spec §14.3).
 */
enum SubscriptionStatus: string
{
    case Incomplete = 'incomplete';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
}
