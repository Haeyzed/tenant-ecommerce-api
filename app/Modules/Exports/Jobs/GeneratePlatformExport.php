<?php

declare(strict_types=1);

namespace App\Modules\Exports\Jobs;

use App\Modules\Exports\Models\DataExport;
use App\Modules\Exports\Models\PlatformExport;
use App\Modules\Exports\Services\PlatformExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Writes one platform export (D-134) on the landlord queue; a completed
 * export is never written twice.
 */
final class GeneratePlatformExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue('landlord-default');
    }

    public function uniqueId(): string
    {
        return 'platform:'.$this->exportId;
    }

    public function handle(PlatformExportService $exports): void
    {
        $export = PlatformExport::query()->find($this->exportId);

        if ($export !== null) {
            $exports->generate($export);
        }
    }

    public function failed(?Throwable $exception): void
    {
        PlatformExport::query()->whereKey($this->exportId)->update([
            'status' => DataExport::FAILED,
            'error' => $exception !== null ? mb_substr(class_basename($exception).': '.$exception->getMessage(), 0, 1000) : 'failed',
        ]);
    }
}
