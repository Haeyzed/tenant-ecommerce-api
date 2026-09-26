<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\URL;

/**
 * A full tenant export for data portability (spec §6.7), delivered to the
 * requesting platform user as a temporary signed link.
 */
final class ExportTenant implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [300];

    public int $timeout = 3600;

    public const int LINK_HOURS = 24;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $platformUserId,
    ) {
        $this->onQueue('tenant-bulk');
    }

    public function handle(TenantExporter $exporter, NotificationDispatchService $notifications): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $user = PlatformUser::query()->find($this->platformUserId);

        if ($tenant === null || $user === null) {
            return;
        }

        $path = $exporter->export($tenant, 'tenant-exports');

        $url = URL::temporarySignedRoute('landlord.tenant-exports.download', now()->addHours(self::LINK_HOURS), [
            'file' => basename($path),
        ]);

        $notifications->dispatch('platform.tenant_export_ready', $user, [
            'name' => $user->name,
            'tenant_name' => $tenant->name,
            'download_url' => $url,
            'expires_in_hours' => self::LINK_HOURS,
        ]);
    }
}
