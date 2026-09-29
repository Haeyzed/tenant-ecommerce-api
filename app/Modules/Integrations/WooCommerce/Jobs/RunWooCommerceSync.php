<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Jobs;

use App\Modules\Integrations\WooCommerce\Models\WooCommerceSettings;
use App\Modules\Integrations\WooCommerce\Services\WooCommerceSyncService;
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
 * A WooCommerce sync run (spec §68.3). A scheduled run syncs every enabled
 * type in dependency order (categories, tax rates, products, orders) and,
 * while sync stays on, dispatches the next run after the interval. It
 * belongs to a chain: a run whose chain id is no longer the settings'
 * (sync switched off and on, or restarted by the watchdog) ends the old
 * chain. A manual run syncs one type. Runs of one tenant never overlap.
 */
final class RunWooCommerceSync implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string SCHEDULED = 'scheduled';

    public const string MANUAL = 'manual';

    public const array TYPES = ['categories', 'tax_rates', 'products', 'orders'];

    /** Releases while another run holds the lock count as attempts. */
    public int $tries = 10;

    public int $timeout = 1800;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $tenantId,
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
        return [(new WithoutOverlapping('woocommerce-sync:'.$this->tenantId))->releaseAfter(60)->expireAfter(2000)];
    }

    public function handle(): void
    {
        Tenant::query()->find($this->tenantId)?->run(function (Tenant $tenant): void {
            if (app(FeatureAccessService::class)->state($tenant, 'woocommerce') !== ModuleState::Enabled) {
                return;
            }

            $settings = WooCommerceSettings::query()->first();

            if ($settings === null) {
                return;
            }

            $sync = app(WooCommerceSyncService::class);

            if ($this->trigger === self::MANUAL) {
                $this->runType($sync, (string) $this->type);

                return;
            }

            // A retired chain stops here, and so does a run delivered before
            // its time (a queue that ignores delays; the watchdog restarts).
            if (! $settings->is_active || $settings->sync_chain_id !== $this->chainId
                || ($this->notBefore !== null && now()->lt(CarbonImmutable::parse($this->notBefore)->subSeconds(5)))) {
                return;
            }

            foreach (self::TYPES as $type) {
                if ($settings->{'sync_'.$type}) {
                    $this->runType($sync, $type);
                }
            }

            $settings->forceFill(['last_synced_at' => now()])->save();

            $next = now()->addMinutes($settings->sync_interval_minutes);
            self::dispatch($this->tenantId, null, self::SCHEDULED, $this->chainId, $next->toIso8601String())->delay($next);
        });
    }

    private function runType(WooCommerceSyncService $sync, string $type): void
    {
        match ($type) {
            'categories' => $sync->syncCategories($this->trigger),
            'tax_rates' => $sync->syncTaxRates($this->trigger),
            'products' => $sync->syncProducts($this->trigger),
            'orders' => $sync->syncOrders($this->trigger),
            default => null,
        };
    }
}
