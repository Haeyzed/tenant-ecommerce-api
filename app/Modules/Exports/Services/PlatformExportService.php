<?php

declare(strict_types=1);

namespace App\Modules\Exports\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Exports\Jobs\GeneratePlatformExport;
use App\Modules\Exports\Models\DataExport;
use App\Modules\Exports\Models\PlatformExport;
use App\Modules\Exports\Support\ExportWriter;
use App\Modules\Exports\Support\PlatformExportRegistry;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\FrontendUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform exports (D-134): the landlord counterpart of DataExportService,
 * with the same lifecycle, writer and retention.
 */
final readonly class PlatformExportService
{
    public function __construct(
        private PlatformExportRegistry $registry,
        private ExportWriter $writer,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * Creates the export, or returns the identical one still in progress.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function request(string $type, array $parameters, string $format, PlatformUser $by): PlatformExport
    {
        if (! $this->registry->has($type)) {
            throw ApiException::unprocessable('export_type_unknown', "Unknown export type [{$type}].");
        }

        $definition = $this->registry->get($type);
        validator(['format' => $format], ['format' => ['required', Rule::in($definition->formats)]])->validate();
        $validated = validator($parameters, $definition->rules)->validate();

        if ($definition->permission !== null && ! $by->hasPermissionTo($definition->permission, 'platform')) {
            throw ApiException::forbidden('forbidden', 'You are not allowed to export this data.', ['permission' => $definition->permission]);
        }

        ksort($validated);

        return DB::connection('landlord')->transaction(function () use ($type, $validated, $format, $by): PlatformExport {
            $existing = PlatformExport::query()->where('export_type', $type)->where('format', $format)->where('requested_by_id', $by->id)
                ->whereIn('status', [DataExport::QUEUED, DataExport::PROCESSING])->lockForUpdate()->get()
                ->first(static fn (PlatformExport $e): bool => $e->parameters == $validated);

            if ($existing !== null) {
                return $existing;
            }

            $export = new PlatformExport;
            $export->forceFill(['export_type' => $type, 'parameters' => $validated, 'format' => $format, 'status' => DataExport::QUEUED, 'requested_by_id' => $by->id])->save();
            GeneratePlatformExport::dispatch($export->id)->afterCommit();

            return $export;
        });
    }

    /**
     * Writes the file (the job's work).
     */
    public function generate(PlatformExport $export): void
    {
        if (in_array($export->status, [DataExport::COMPLETED, DataExport::EXPIRED], true)) {
            return;
        }

        $export->forceFill(['status' => DataExport::PROCESSING, 'error' => null])->save();
        $file = $this->writer->write($this->registry->get($export->export_type), (array) $export->parameters, $export->format);
        $export->addMedia($file['path'])->usingFileName($export->export_type.'-'.$export->id.'.'.$export->format)->toMediaCollection('file');
        $export->forceFill(['status' => DataExport::COMPLETED, 'row_count' => $file['rows'], 'completed_at' => now(), 'expires_at' => now()->addDays(DataExport::RETENTION_DAYS)])->save();

        if ($export->requestedBy !== null) {
            $this->notifications->dispatch('platform.export_ready', $export->requestedBy, [
                'export_type' => str_replace('_', ' ', $export->export_type),
                'download_url' => FrontendUrl::platformAdmin('/exports/'.$export->id),
                'expires_at' => $export->expires_at?->toFormattedDateString() ?? '',
            ], data: ['export_id' => $export->id]);
        }
    }

    /**
     * @return LengthAwarePaginator<int, PlatformExport>
     */
    public function listFor(PlatformUser $user, int $perPage = 25): LengthAwarePaginator
    {
        return PlatformExport::query()->where('requested_by_id', $user->id)->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Deletes expired files and marks their rows (daily landlord maintenance).
     */
    public function expireFiles(): int
    {
        $count = 0;

        PlatformExport::query()->where('status', DataExport::COMPLETED)->where('expires_at', '<=', now())
            ->chunkById(200, static function ($exports) use (&$count): void {
                foreach ($exports as $export) {
                    $export->clearMediaCollection('file');
                    $export->forceFill(['status' => DataExport::EXPIRED])->save();
                    $count++;
                }
            });

        return $count;
    }
}
