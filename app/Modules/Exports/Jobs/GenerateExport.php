<?php

declare(strict_types=1);

namespace App\Modules\Exports\Jobs;

use App\Modules\Exports\Models\DataExport;
use App\Modules\Exports\Support\ExportContext;
use App\Modules\Exports\Support\ExportRegistry;
use App\Modules\Exports\Support\ExportWriter;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Writes one tenant export into a private file (spec §19.4). The tenant
 * comes from the queue tenancy bootstrapper (the job runs inside the
 * tenant that dispatched it); the requester's scope from ExportContext.
 */
final class GenerateExport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    /** Rows a PDF holds at most; the file notes when more were left out. */
    public const int PDF_MAX_ROWS = ExportWriter::PDF_MAX_ROWS;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue('tenant-bulk');
    }

    public function uniqueId(): string
    {
        return (string) tenant()?->getTenantKey().':'.$this->exportId;
    }

    public function handle(ExportRegistry $registry, ExportWriter $writer, NotificationDispatchService $notifications): void
    {
        $export = DataExport::query()->find($this->exportId);

        // A retry after completion (or expiry) does nothing twice.
        if ($export === null || in_array($export->status, [DataExport::COMPLETED, DataExport::EXPIRED], true)) {
            return;
        }

        $export->forceFill(['status' => DataExport::PROCESSING, 'error' => null])->save();

        // Types that depend on who asks read the requester here, never from the parameters.
        app()->instance(ExportContext::class, new ExportContext($export->requestedBy));
        $file = $writer->write($registry->get($export->export_type), (array) $export->parameters, $export->format);

        // Types may hold ":" (a module prefix), which is not portable in
        // file names (an NTFS stream separator).
        $export->addMedia($file['path'])
            ->usingFileName(preg_replace('/[^A-Za-z0-9_-]+/', '-', $export->export_type).'-'.$export->id.'.'.$export->format)
            ->toMediaCollection('file');

        $export->forceFill([
            'status' => DataExport::COMPLETED,
            'row_count' => $file['rows'],
            'completed_at' => now(),
            'expires_at' => now()->addDays(DataExport::RETENTION_DAYS),
        ])->save();

        $this->notify($export, $notifications);
    }

    public function failed(?Throwable $exception): void
    {
        DataExport::query()->whereKey($this->exportId)->update([
            'status' => DataExport::FAILED,
            'error' => $exception !== null ? mb_substr(class_basename($exception).': '.$exception->getMessage(), 0, 1000) : 'failed',
        ]);
    }

    private function notify(DataExport $export, NotificationDispatchService $notifications): void
    {
        $requester = $export->requestedBy;

        if ($requester === null) {
            return;
        }

        if ($export->requested_by_type === 'customer') {
            /** @var Tenant $tenant */
            $tenant = tenant();
            $signed = URL::temporarySignedRoute('tenant.exports.signed-download', $export->expires_at ?? now()->addDays(DataExport::RETENTION_DAYS), ['export' => $export->id]);

            $notifications->dispatch('account.data_export_ready', $requester, [
                'customer_name' => (string) $requester->getAttribute('name'),
                'download_url' => FrontendUrl::storefront($tenant, '/account/export', ['link' => $signed]),
                'expires_at' => $export->expires_at?->toFormattedDateString() ?? '',
            ]);

            return;
        }

        /** @var Tenant $tenant */
        $tenant = tenant();

        $notifications->dispatch('export.ready', $requester, [
            'export_type' => str_replace(['_', ':'], ' ', $export->export_type),
            'download_url' => FrontendUrl::tenantAdmin($tenant, '/exports/'.$export->id),
            'expires_at' => $export->expires_at?->toFormattedDateString() ?? '',
        ], data: ['export_id' => $export->id]);
    }
}
