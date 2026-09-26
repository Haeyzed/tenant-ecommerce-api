<?php

declare(strict_types=1);

namespace App\Shared\Media;

use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\UsageCounterRegistry;
use Illuminate\Http\UploadedFile;

/**
 * max_storage_mb (spec §11.8, §19.3): every upload is checked against the
 * tenant's storage limit before it is stored. Storage is never unlimited.
 */
final readonly class StorageQuota
{
    private const int BYTES_PER_MB = 1048576;

    public function __construct(
        private PlanLimitService $limits,
        private UsageCounterRegistry $counters,
    ) {}

    /**
     * @param  int|UploadedFile|list<UploadedFile>  $incoming  bytes, or the files about to be stored
     */
    public function assertAllows(int|UploadedFile|array $incoming): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return;
        }

        $bytes = match (true) {
            is_int($incoming) => $incoming,
            $incoming instanceof UploadedFile => (int) $incoming->getSize(),
            default => array_sum(array_map(static fn (UploadedFile $f): int => (int) $f->getSize(), $incoming)),
        };

        $limitMb = $this->limits->getLimit($tenant, 'max_storage_mb') ?? PHP_INT_MAX;
        $usedMb = $this->counters->count('max_storage_mb');
        $afterMb = (int) ceil(($usedMb * self::BYTES_PER_MB + $bytes) / self::BYTES_PER_MB);

        if ($afterMb > $limitMb) {
            throw ApiException::forbidden('limit_reached', 'Your storage is full. Upgrade your plan or remove files.', [
                'limit' => 'max_storage_mb',
                'used' => $usedMb,
                'limit_value' => $limitMb,
            ]);
        }
    }
}
