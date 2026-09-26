<?php

declare(strict_types=1);

namespace App\Modules\Plans\Enums;

/**
 * The single computed state of a module for a tenant (spec §11.5).
 */
enum ModuleState: string
{
    case Unavailable = 'unavailable';
    case Available = 'available';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Locked = 'locked';
    case Suspended = 'suspended';

    /**
     * The 403 error code used when a route is refused in this state (§70.8).
     */
    public function errorCode(): string
    {
        return match ($this) {
            self::Unavailable => 'feature_unavailable',
            self::Available, self::Disabled, self::Enabled => 'module_disabled',
            self::Locked => 'module_locked',
            self::Suspended => 'module_suspended',
        };
    }
}
