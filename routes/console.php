<?php

declare(strict_types=1);

use App\Modules\Affiliates\Jobs\ApproveEligibleAffiliateCommissions;
use App\Modules\Affiliates\Jobs\GenerateAffiliatePayouts;
use App\Modules\Billing\Jobs\ProcessSubscriptionRenewal;
use App\Modules\Dashboard\Jobs\RecordPlatformDailyMetrics;
use App\Modules\Tenancy\Jobs\DispatchTenantDailyMaintenance;
use App\Modules\Tenancy\Jobs\RecheckCustomDomains;
use App\Modules\Tenancy\Jobs\RunLandlordDailyMaintenance;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler (spec §72.2)
|--------------------------------------------------------------------------
|
| Landlord entries only. Per-tenant work is reached through one daily job
| per tenant (DispatchTenantDailyMaintenance), never one entry per tenant
| per feature. Every entry runs on one server and never overlaps itself.
|
*/

Schedule::job(new ProcessSubscriptionRenewal)
    ->dailyAt('01:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping()
    ->name('billing:process-renewals');

Schedule::job(new RunLandlordDailyMaintenance)
    ->dailyAt('02:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping()
    ->name('landlord:daily-maintenance');

// Pending verifications and TLS probing; the daily re-check of active
// domains is dispatched by the landlord daily maintenance (§7.5).
Schedule::job(new RecheckCustomDomains)
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping()
    ->name('domains:hourly-checks');

// Records the previous day at the first run after midnight in the
// platform timezone (00:30 local); later runs find the day recorded.
Schedule::job(new RecordPlatformDailyMetrics)
    ->hourlyAt(30)
    ->onOneServer()
    ->withoutOverlapping()
    ->name('dashboard:record-platform-daily-metrics');

Schedule::job(new ApproveEligibleAffiliateCommissions)
    ->dailyAt('03:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping()
    ->name('affiliates:approve-eligible-commissions');

// Acts only on affiliate_payout_day from 04:00 in the platform timezone.
Schedule::job(new GenerateAffiliatePayouts)
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping()
    ->name('affiliates:generate-payouts');

Schedule::job(new DispatchTenantDailyMaintenance)
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping()
    ->name('tenants:dispatch-daily-maintenance');
