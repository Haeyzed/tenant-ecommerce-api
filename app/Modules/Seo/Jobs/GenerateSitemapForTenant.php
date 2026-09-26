<?php

declare(strict_types=1);

namespace App\Modules\Seo\Jobs;

use App\Modules\Seo\Support\SitemapBuilder;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Rebuilds a tenant's sitemap (spec §30.2). Dispatched on publish and
 * unpublish (unique per tenant, delayed as a debounce) and by the daily
 * maintenance when content changed since the last build.
 */
final class GenerateSitemapForTenant implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int DEBOUNCE_MINUTES = 5;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $tenantId)
    {
        $this->onQueue('tenant-default');
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(SitemapBuilder $builder): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $tenant->provisioned_at === null || ! in_array($tenant->status, TenantStatus::jobRunnable(), true)) {
            return;
        }

        $tenant->run(static fn () => $builder->buildForTenant($tenant));
    }
}
