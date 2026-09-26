<?php

declare(strict_types=1);

namespace App\Modules\Plans\Enums;

enum OverrideEffect: string
{
    case Grant = 'grant';
    case Revoke = 'revoke';
    case Suspend = 'suspend';
}
