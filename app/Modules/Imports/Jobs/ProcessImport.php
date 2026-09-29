<?php

declare(strict_types=1);

namespace App\Modules\Imports\Jobs;

use App\Modules\Imports\Models\DataImport;
use App\Modules\Imports\Services\DataImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Applies one import (D-135) inside the tenant that queued it (the queue
 * tenancy bootstrapper restores it). Retries resume after the last
 * committed row, so a retry never applies a row twice.
 */
final class ProcessImport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('tenant-bulk');
    }

    public function uniqueId(): string
    {
        return (string) tenant()?->getTenantKey().':'.$this->importId;
    }

    public function handle(DataImportService $imports): void
    {
        $import = DataImport::query()->find($this->importId);

        if ($import !== null) {
            $imports->process($import);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(DataImportService::class)->fail($this->importId, 'The import stopped unexpectedly. Rows before row '
            .((int) DataImport::query()->whereKey($this->importId)->value('last_row') + 1).' were applied; upload the rest again.');
    }
}
