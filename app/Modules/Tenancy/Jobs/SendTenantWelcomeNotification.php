<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends tenant.provisioning_complete (spec §9.4 step 7).
 */
final class SendTenantWelcomeNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 60;

    public function __construct(public readonly string $tenantId)
    {
        $this->onQueue('landlord-default');
    }

    public function handle(NotificationDispatchService $notifications): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $notifications->dispatch('tenant.provisioning_complete', $tenant, [
            'owner_name' => $tenant->owner_name,
            'tenant_name' => $tenant->name,
            'admin_url' => FrontendUrl::tenantAdmin($tenant, '/'),
        ]);
    }
}
