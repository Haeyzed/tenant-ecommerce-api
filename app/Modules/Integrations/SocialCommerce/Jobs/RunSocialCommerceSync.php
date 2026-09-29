<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Jobs;

use App\Modules\Integrations\SocialCommerce\Models\SocialCommerceAccount;
use App\Modules\Integrations\SocialCommerce\Services\SocialCommerceSyncService;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One account's sync run (spec §69.2), self-scheduling like WooCommerce's:
 * a scheduled run syncs products then orders and dispatches the next run
 * after the interval while its chain is current; a manual run syncs one
 * type. Runs of one account never overlap.
 */
final class RunSocialCommerceSync implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string SCHEDULED = 'scheduled';

    public const string MANUAL = 'manual';

    /** Releases while another run holds the lock count as attempts. */
    public int $tries = 10;

    public int $timeout = 1800;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $tenantId,
        public readonly int $accountId,
        public readonly ?string $type,
        public readonly string $trigger,
        public readonly ?string $chainId = null,
        public readonly ?string $notBefore = null,
    ) {
        $this->onQueue('tenant-bulk');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('social-commerce-sync:'.$this->tenantId.':'.$this->accountId))->releaseAfter(60)->expireAfter(2000)];
    }

    public function handle(): void
    {
        Tenant::query()->find($this->tenantId)?->run(function (Tenant $tenant): void {
            if (app(FeatureAccessService::class)->state($tenant, 'social_commerce') !== ModuleState::Enabled) {
                return;
            }

            $account = SocialCommerceAccount::query()->find($this->accountId);

            if ($account === null || ! $account->is_active) {
                return;
            }

            $sync = app(SocialCommerceSyncService::class);

            if ($this->trigger === self::MANUAL) {
                $this->type === 'orders' ? $sync->syncOrders($account, self::MANUAL) : $sync->syncProducts($account, self::MANUAL);

                return;
            }

            if ($account->sync_chain_id !== $this->chainId
                || ($this->notBefore !== null && now()->lt(CarbonImmutable::parse($this->notBefore)->subSeconds(5)))) {
                return;
            }

            if ($account->sync_products) {
                $sync->syncProducts($account, self::SCHEDULED);
            }

            if ($account->sync_orders && $account->takesOrders()) {
                $sync->syncOrders($account, self::SCHEDULED);
            }

            $account->forceFill(['last_synced_at' => now()])->save();
            $next = now()->addMinutes($account->sync_interval_minutes);
            self::dispatch($this->tenantId, $account->id, null, self::SCHEDULED, $this->chainId, $next->toIso8601String())->delay($next);
        });
    }
}
