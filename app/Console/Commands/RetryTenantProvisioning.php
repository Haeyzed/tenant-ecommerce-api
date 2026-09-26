<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantRegistration;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-queues provisioning of a tenant in provisioning_failed (spec §9.4,
 * §77.6). Every provisioning step is idempotent, so a retry is safe.
 */
#[Signature('tenants:retry-provisioning {tenant : The tenant ID}')]
#[Description('Retry provisioning of a tenant whose provisioning failed')]
final class RetryTenantProvisioning extends Command
{
    public function handle(): int
    {
        $tenant = Tenant::query()->find((string) $this->argument('tenant'));

        if ($tenant === null) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        if ($tenant->status !== TenantStatus::ProvisioningFailed) {
            $this->error("The tenant is {$tenant->status->value}, not provisioning_failed.");

            return self::FAILURE;
        }

        $tenant->forceFill(['status' => TenantStatus::Provisioning, 'status_reason' => null])->save();

        ProvisionTenantDatabase::dispatch(
            (string) $tenant->id,
            TenantRegistration::query()->where('tenant_id', $tenant->id)->value('id'),
        );

        $this->info("Provisioning of {$tenant->name} was queued again.");

        return self::SUCCESS;
    }
}
