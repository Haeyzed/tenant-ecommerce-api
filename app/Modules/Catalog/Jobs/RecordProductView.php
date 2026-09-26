<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Services\ProductViewService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Records one storefront product view off the request (spec §29.6).
 */
final class RecordProductView implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 30;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $productId,
        public readonly ?int $customerId,
        public readonly string $viewedAt,
    ) {
        $this->onQueue('tenant-default');
    }

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        $tenant?->run(fn () => app(ProductViewService::class)->store($this->productId, $this->customerId, Carbon::parse($this->viewedAt)));
    }
}
