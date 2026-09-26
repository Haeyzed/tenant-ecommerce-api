<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantRegistration;
use App\Modules\Tenancy\Services\TenantRegistrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Creates and prepares a tenant's database (spec §9.4). The payload holds
 * IDs only, never secrets. While provisioning is switched off the job
 * re-dispatches a fresh copy of itself, so holding never uses its try.
 */
final class ProvisionTenantDatabase implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    private const int HOLD_MINUTES = 10;

    public function __construct(
        public readonly string $tenantId,
        public readonly ?int $registrationId = null,
    ) {
        $this->onQueue('landlord-default');
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(TenantRegistrationService $registrations, PlatformSettingsService $settings): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $tenant->status !== TenantStatus::Provisioning) {
            return;
        }

        if (! (bool) $settings->get('tenant_provisioning_enabled', true)) {
            self::dispatch($this->tenantId, $this->registrationId)->delay(now()->addMinutes(self::HOLD_MINUTES));

            return;
        }

        $registration = $this->registrationId !== null ? TenantRegistration::query()->find($this->registrationId) : null;

        $registrations->provision($tenant, $registration);
    }

    public function failed(?Throwable $exception): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $tenant->forceFill([
            'status' => TenantStatus::ProvisioningFailed,
            'status_reason' => 'provisioning_failed',
        ])->save();

        $superAdmins = PlatformUser::query()->withPlatformRole('super-admin')->where('is_active', true)->get();

        app(NotificationDispatchService::class)->dispatch('platform.provisioning_failed', $superAdmins, [
            'tenant_name' => $tenant->name,
            'tenant_id' => (string) $tenant->id,
            'step' => $exception !== null ? class_basename($exception) : 'unknown',
        ]);
    }
}
