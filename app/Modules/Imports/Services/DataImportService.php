<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Jobs\ProcessImport;
use App\Modules\Imports\Models\DataImport;
use App\Modules\Imports\Models\DataImportError;
use App\Modules\Imports\Support\ImportDefinition;
use App\Modules\Imports\Support\ImportRegistry;
use App\Modules\Imports\Support\SpreadsheetRows;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\FrontendUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Maatwebsite\Excel\Excel as ExcelReader;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * Spreadsheet imports (D-135). The upload is validated and stored
 * privately, and a ProcessImport job applies it row by row through the
 * owning service. Each row commits together with the last_row checkpoint,
 * so a retried job resumes where it stopped and never applies a row twice.
 * Rejected rows are recorded with their row number, field and message;
 * the other rows carry on.
 */
final readonly class DataImportService
{
    /** Rows one file may hold (after the heading). */
    public const int MAX_ROWS = 50000;

    /** Upload limit in kilobytes. */
    public const int MAX_KB = 10240;

    public function __construct(
        private ImportRegistry $registry,
        private FeatureAccessService $features,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * Validates the request, stores the file and queues the import.
     */
    public function request(string $type, UploadedFile $file, ?string $mode, User $by): DataImport
    {
        $definition = $this->definition($type, $by);
        $validated = validator(['file' => $file, 'mode' => $mode ?? $definition->modes[0]], [
            // MIME is sniffed from the content, not the name (§19.3).
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:'.self::MAX_KB],
            'mode' => ['required', Rule::in($definition->modes)],
        ])->validate();

        $extension = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        $name = mb_substr(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'import', 0, 100).'.'.$extension;

        return DB::connection('tenant')->transaction(function () use ($definition, $validated, $file, $name, $by): DataImport {
            $import = new DataImport;
            $import->forceFill([
                'import_type' => $definition->type,
                'mode' => $validated['mode'],
                'status' => DataImport::QUEUED,
                'original_filename' => $name,
                'requested_by_id' => $by->id,
            ])->save();

            $import->addMedia($file)->usingFileName('import-'.$import->id.'.'.pathinfo($name, PATHINFO_EXTENSION))->preservingOriginal()->toMediaCollection('file');
            ActivityRecorder::tenant('imports', "Import of {$definition->label} requested", $import, ['type' => $definition->type, 'mode' => $validated['mode']], $by);
            ProcessImport::dispatch($import->id)->afterCommit();

            return $import;
        });
    }

    /**
     * Runs (or resumes) an import; called by ProcessImport.
     */
    public function process(DataImport $import): void
    {
        if ($import->isFinished()) {
            return;
        }

        $definition = $this->registry->get($import->import_type);
        $import->forceFill(['status' => DataImport::PROCESSING, 'started_at' => $import->started_at ?? now(), 'error' => null])->save();
        $path = $this->localCopy($import);
        $checkedHeadings = false;

        try {
            Excel::import(new SpreadsheetRows(function (int $index, array $row) use ($import, $definition, &$checkedHeadings): void {
                if (! $checkedHeadings) {
                    $missing = array_values(array_diff($definition->required, array_keys($row)));

                    if ($missing !== []) {
                        throw new InvalidArgumentException('The file is missing the column(s): '.implode(', ', $missing).'. Download the template for this import.');
                    }

                    $checkedHeadings = true;
                }

                if ($index <= $import->last_row) {
                    return;
                }

                if ($index - 1 > self::MAX_ROWS) {
                    throw new InvalidArgumentException('A file may hold at most '.number_format(self::MAX_ROWS).' rows. Split it and import the rest separately.');
                }

                $this->applyRow($import, $definition, $index, $row);
            }), $path, null, str_ends_with($path, '.xlsx') ? ExcelReader::XLSX : ExcelReader::CSV);
        } catch (InvalidArgumentException $e) {
            // A whole-file problem (columns, size): the import stops here.
            $this->finish($import, DataImport::FAILED, $e->getMessage());

            return;
        } finally {
            @unlink($path);
        }

        if ($import->processed_rows === 0) {
            $this->finish($import, DataImport::FAILED, 'The file has no data rows.');

            return;
        }

        $this->finish($import, $import->failed_count > 0 ? DataImport::COMPLETED_WITH_ERRORS : DataImport::COMPLETED, null);
    }

    /**
     * The job failed after its retries (an outage, not a bad row).
     */
    public function fail(int $importId, string $reason): void
    {
        $import = DataImport::query()->find($importId);

        if ($import !== null && ! $import->isFinished()) {
            $this->finish($import, DataImport::FAILED, $reason);
        }
    }

    /**
     * @param  array{status?: string, import_type?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, DataImport>
     */
    public function listImports(User $user, array $filters): LengthAwarePaginator
    {
        return DataImport::query()->where('requested_by_id', $user->id)
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['import_type']), static fn ($q) => $q->where('import_type', $filters['import_type']))
            ->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * An import of the user's own, else 404.
     */
    public function getImport(User $user, int $id): DataImport
    {
        return DataImport::query()->where('requested_by_id', $user->id)->findOrFail($id);
    }

    /**
     * @return LengthAwarePaginator<int, DataImportError>
     */
    public function listErrors(DataImport $import, int $perPage = 50): LengthAwarePaginator
    {
        return DataImportError::query()->where('data_import_id', $import->id)->orderBy('row_number')->orderBy('id')->paginate($perPage);
    }

    /**
     * The types the user may import, with their columns (templates).
     *
     * @return list<ImportDefinition>
     */
    public function typesFor(User $user): array
    {
        $tenant = tenant();

        return array_values(array_filter($this->registry->all(), fn (ImportDefinition $d): bool => $user->hasPermissionTo($d->permission, 'staff')
            && ($d->module === null || ($tenant instanceof Tenant && $this->features->tenantCanAccess($tenant, $d->module)))));
    }

    public function definition(string $type, User $by): ImportDefinition
    {
        if (! $this->registry->has($type)) {
            throw ApiException::unprocessable('import_type_unknown', "Unknown import type [{$type}].");
        }

        $definition = $this->registry->get($type);

        if (! $by->hasPermissionTo($definition->permission, 'staff')) {
            throw ApiException::forbidden('forbidden', 'You are not allowed to import this data.', ['permission' => $definition->permission]);
        }

        $tenant = tenant();

        if ($definition->module !== null && (! $tenant instanceof Tenant || ! $this->features->tenantCanAccess($tenant, $definition->module))) {
            throw ApiException::forbidden('feature_unavailable', 'This import is not available on your plan.', ['module' => $definition->module]);
        }

        return $definition;
    }

    /**
     * Deletes uploaded files past their retention (daily tenant maintenance).
     */
    public function expireFiles(): int
    {
        $count = 0;

        DataImport::query()->whereIn('status', [DataImport::COMPLETED, DataImport::COMPLETED_WITH_ERRORS, DataImport::FAILED])
            ->where('completed_at', '<', now()->subDays(DataImport::FILE_RETENTION_DAYS))->whereHas('media')
            ->chunkById(200, static function ($imports) use (&$count): void {
                foreach ($imports as $import) {
                    $import->clearMediaCollection('file');
                    $count++;
                }
            });

        return $count;
    }

    /**
     * One row and its checkpoint in one transaction; a rejected row records
     * its errors and moves the checkpoint on.
     *
     * @param  array<string, mixed>  $raw
     */
    private function applyRow(DataImport $import, ImportDefinition $definition, int $index, array $raw): void
    {
        $row = [];

        foreach ($raw as $key => $value) {
            $row[(string) $key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        $errors = [];

        foreach ($row as $key => $value) {
            // Spreadsheet formulas are refused, never evaluated or stored.
            if (is_string($value) && str_starts_with($value, '=')) {
                $errors[] = [$key, 'Formulas are not allowed; enter the value itself.', $value];
            }
        }

        $result = null;

        if ($errors === []) {
            try {
                $result = DB::connection('tenant')->transaction(function () use ($import, $definition, $index, $row): string {
                    $result = ($definition->apply)([...$row, '_row' => $index], $import);
                    $this->checkpoint($import, $index, $result);

                    return $result;
                });
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $errors[] = [(string) $field, (string) ($messages[0] ?? 'Invalid.'), $row[(string) $field] ?? null];
                }
            } catch (ApiException|ModelNotFoundException|UniqueConstraintViolationException|\DomainException $e) {
                $errors[] = [null, $e instanceof ApiException || $e instanceof \DomainException ? $e->getMessage() : ($e instanceof ModelNotFoundException ? 'A referenced record does not exist.' : 'This row duplicates an existing record.'), null];
            }
        }

        if ($errors !== []) {
            DB::connection('tenant')->transaction(function () use ($import, $definition, $index, $errors): void {
                foreach ($errors as [$field, $message, $value]) {
                    $error = new DataImportError;
                    $error->forceFill([
                        'data_import_id' => $import->id,
                        'row_number' => $index,
                        'field' => $field === null ? null : mb_substr($field, 0, 64),
                        'message' => mb_substr($message, 0, 500),
                        'value' => $value === null || in_array($field, $definition->sensitive, true) ? null : mb_substr(is_scalar($value) ? (string) $value : (string) json_encode($value), 0, 255),
                    ])->save();
                }

                $this->checkpoint($import, $index, 'failed');
            });
        }
    }

    private function checkpoint(DataImport $import, int $index, string $result): void
    {
        $import->forceFill([
            'last_row' => $index,
            'processed_rows' => $import->processed_rows + 1,
            'created_count' => $import->created_count + ($result === 'created' ? 1 : 0),
            'updated_count' => $import->updated_count + ($result === 'updated' ? 1 : 0),
            'failed_count' => $import->failed_count + ($result === 'failed' ? 1 : 0),
        ])->save();
    }

    private function finish(DataImport $import, string $status, ?string $error): void
    {
        $import->forceFill(['status' => $status, 'error' => $error, 'completed_at' => now()])->save();
        $tenant = tenant();
        $requester = $import->requestedBy;

        if ($requester !== null && $tenant instanceof Tenant) {
            $this->notifications->dispatch('import.completed', $requester, [
                'import_type' => str_replace('_', ' ', $import->import_type),
                'status' => str_replace('_', ' ', $status),
                'created' => (string) $import->created_count,
                'updated' => (string) $import->updated_count,
                'failed' => (string) $import->failed_count,
                'import_url' => FrontendUrl::tenantAdmin($tenant, '/imports/'.$import->id),
            ], data: ['import_id' => $import->id]);
        }
    }

    /**
     * The upload on a local path the reader can open (object storage too).
     */
    private function localCopy(DataImport $import): string
    {
        $media = $import->getFirstMedia('file') ?? throw new RuntimeException('The uploaded file is no longer available.');
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'import-'.Str::uuid().'.'.strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION));
        $stream = Storage::disk($media->disk)->readStream($media->getPathRelativeToRoot()) ?? throw new RuntimeException('The uploaded file could not be read.');
        file_put_contents($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $path;
    }
}
