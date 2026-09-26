<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Access\Jobs\SyncTenantDefaultsForAllTenants;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Deploy step (spec §12.6, §77.6): queues the additive defaults sync for
 * every tenant behind the current permissions version.
 */
#[Signature('tenants:sync-defaults {--force : Sync every tenant, not only those behind}')]
#[Description('Queue the tenant defaults sync (permissions, roles, templates, module defaults)')]
final class SyncTenantDefaultsCommand extends Command
{
    public function handle(): int
    {
        SyncTenantDefaultsForAllTenants::dispatch((bool) $this->option('force'));

        $this->info('Tenant defaults sync queued.');

        return self::SUCCESS;
    }
}
