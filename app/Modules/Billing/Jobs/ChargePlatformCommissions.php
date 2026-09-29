<?php

declare(strict_types=1);

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Models\PlatformCommission;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The monthly platform commission run (D-138): charges each tenant's net
 * pending commission through its saved subscription authorization. The
 * charge reference is fixed per tenant and month, so a rerun or retry
 * never charges twice. One tenant's failure never stops the run.
 */
final class ChargePlatformCommissions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [300, 900];

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('landlord-default');
    }

    public function handle(SubscriptionService $subscriptions): void
    {
        $month = now()->format('Y-m');

        PlatformCommission::query()->where('status', PlatformCommission::PENDING)
            ->distinct()->orderBy('tenant_id')->pluck('tenant_id')
            ->each(static function (string $tenantId) use ($subscriptions, $month): void {
                $subscription = Subscription::query()->where('tenant_id', $tenantId)->latest('id')->first();

                if ($subscription === null) {
                    return;
                }

                try {
                    $subscriptions->chargeCommission($subscription, $month);
                } catch (Throwable $e) {
                    report($e);
                    Log::error('Platform commission charge failed for one tenant.', ['tenant_id' => $tenantId]);
                }
            });
    }
}
