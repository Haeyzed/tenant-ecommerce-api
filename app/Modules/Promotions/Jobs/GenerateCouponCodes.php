<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Jobs;

use App\Modules\Promotions\Models\Promotion;
use App\Modules\Promotions\Services\CouponService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates a large batch of coupon codes (spec §37.8, §72). Not retried:
 * a retry after a partial run would generate more codes than asked for;
 * a failure is logged and staff generate the remainder.
 */
final class GenerateCouponCodes implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $promotionId,
        public readonly int $count,
        public readonly string $prefix,
        public readonly int $length,
        public readonly ?int $usageLimit,
    ) {
        $this->onQueue('tenant-bulk');
    }

    public function handle(CouponService $coupons): void
    {
        Tenant::query()->find($this->tenantId)?->run(function () use ($coupons): void {
            $promotion = Promotion::query()->find($this->promotionId);

            if ($promotion !== null) {
                $coupons->insertCodes($promotion, $this->count, $this->prefix, $this->length, $this->usageLimit);
            }
        });
    }

    public function failed(Throwable $e): void
    {
        Log::error('Coupon code generation failed.', ['tenant_id' => $this->tenantId, 'promotion_id' => $this->promotionId, 'count' => $this->count, 'error' => $e::class]);
    }
}
