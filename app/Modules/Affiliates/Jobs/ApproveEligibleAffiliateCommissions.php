<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Jobs;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Services\AffiliateCommissionService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\PlatformSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily (spec §72.2): in automatic mode approves pending commissions past
 * their hold and not under review; in manual mode tells the affiliate
 * managers how many are waiting.
 */
final class ApproveEligibleAffiliateCommissions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('landlord-default');
    }

    public function handle(AffiliateCommissionService $commissions, PlatformSettingsService $settings, NotificationDispatchService $notifications): void
    {
        if ($settings->get('affiliate_commission_approval', 'manual') === 'automatic') {
            $commissions->approveEligible();

            return;
        }

        $waiting = AffiliateCommission::query()->eligible()->where('type', AffiliateCommission::COMMISSION)->count();

        if ($waiting === 0) {
            return;
        }

        $managers = PlatformUser::query()->withPlatformRole('affiliate-manager')->where('is_active', true)->get();

        if ($managers->isNotEmpty()) {
            $notifications->dispatch('affiliate.commissions_awaiting_approval', $managers, ['count' => (string) $waiting]);
        }
    }
}
