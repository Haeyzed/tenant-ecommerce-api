<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Events\TenantProvisioned;
use App\Modules\Tenancy\Jobs\SendTenantWelcomeNotification;

final class QueueWelcomeNotification
{
    public function handle(TenantProvisioned $event): void
    {
        SendTenantWelcomeNotification::dispatch((string) $event->tenant->id);
    }
}
