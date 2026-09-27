<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Re-dispatches every failed accounting posting (spec §57.3 step 4), for
 * example once staff have created or reopened the fiscal period an entry
 * belongs to.
 */
#[Signature('accounting:retry-postings {tenant? : Only this tenant id}')]
#[Description('Re-dispatch failed accounting posting requests')]
final class RetryAccountingPostings extends Command
{
    public function handle(): int
    {
        $tenants = Tenant::query()
            ->whereNotNull('provisioned_at')
            ->whereNull('purged_at')
            ->when($this->argument('tenant'), static fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($tenants as $tenant) {
            $count = (int) $tenant->run(static fn (): int => Schema::connection('tenant')->hasTable('accounting_posting_requests')
                ? app(AccountingService::class)->retryFailed()
                : 0);

            if ($count > 0) {
                $this->line("Tenant {$tenant->id}: {$count} postings re-dispatched");
            }

            $total += $count;
        }

        $this->info("Re-dispatched {$total} postings across {$tenants->count()} tenants.");

        return self::SUCCESS;
    }
}
