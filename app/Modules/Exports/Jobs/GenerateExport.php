<?php

declare(strict_types=1);

namespace App\Modules\Exports\Jobs;

use App\Modules\Exports\Models\DataExport;
use App\Modules\Exports\Support\ExportRegistry;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Support\FrontendUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Throwable;

/**
 * Streams one export into a private file (spec §19.4). Rows are written as
 * they are read, so memory stays flat whatever the export size.
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

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue('tenant-bulk');
    }

    public function uniqueId(): string
    {
        return (string) tenant()?->getTenantKey().':'.$this->exportId;
    }

    public function handle(ExportRegistry $registry, NotificationDispatchService $notifications): void
    {
        $export = DataExport::query()->find($this->exportId);

        if ($export === null || in_array($export->status, [DataExport::COMPLETED, DataExport::EXPIRED], true)) {
            return;
        }

        $export->forceFill(['status' => DataExport::PROCESSING, 'error' => null])->save();

        $definition = $registry->get($export->export_type);
        $path = tempnam(sys_get_temp_dir(), 'export');
        $handle = fopen((string) $path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Could not open the export file.');
        }

        $rows = 0;

        try {
            if ($export->format === 'csv') {
                fputcsv($handle, array_values($definition->columns), escape: '');
            } else {
                fwrite($handle, '[');
            }

            foreach (($definition->rows)($export->parameters) as $row) {
                $values = [];

                foreach (array_keys($definition->columns) as $key) {
                    $values[$key] = $row[$key] ?? null;
                }

                if ($export->format === 'csv') {
                    fputcsv($handle, array_map(self::csvCell(...), array_values($values)), escape: '');
                } else {
                    fwrite($handle, ($rows > 0 ? ',' : '').json_encode($values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                }

                $rows++;
            }

            if ($export->format === 'json') {
                fwrite($handle, ']');
            }

            fclose($handle);

            $export->addMedia((string) $path)
                ->usingFileName($export->export_type.'-'.$export->id.'.'.$export->format)
                ->toMediaCollection('file');
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            @unlink((string) $path);

            throw $e;
        }

        $export->forceFill([
            'status' => DataExport::COMPLETED,
            'row_count' => $rows,
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

    /**
     * Formulas are neutralised, so a spreadsheet never executes exported
     * text (CSV injection).
     */
    private static function csvCell(mixed $value): string
    {
        $text = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
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
