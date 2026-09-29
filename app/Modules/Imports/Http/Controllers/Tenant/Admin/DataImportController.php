<?php

declare(strict_types=1);

namespace App\Modules\Imports\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Models\DataImport;
use App\Modules\Imports\Models\DataImportError;
use App\Modules\Imports\Services\DataImportService;
use App\Modules\Imports\Support\ImportDefinition;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Spreadsheet imports (D-135). A user sees and follows only their own
 * imports; each type checks its own permission.
 */
final class DataImportController extends Controller
{
    public function __construct(private readonly DataImportService $imports) {}

    /**
     * The types the user may import, with their columns.
     */
    public function types(Request $request): JsonResponse
    {
        return APIResponse::success(array_map(static fn (ImportDefinition $d): array => [
            'type' => $d->type,
            'label' => $d->label,
            'columns' => $d->columns,
            'required' => $d->required,
            'modes' => $d->modes,
            'max_rows' => DataImportService::MAX_ROWS,
            'max_file_kb' => DataImportService::MAX_KB,
        ], $this->imports->typesFor($this->user($request))));
    }

    /**
     * A CSV holding just the heading row.
     */
    public function template(Request $request, string $type): StreamedResponse
    {
        $definition = $this->imports->definition($type, $this->user($request));

        return response()->streamDownload(static function () use ($definition): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, array_keys($definition->columns), escape: '');
            fclose($out);
        }, $definition->type.'-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(DataImport::STATUSES)],
            'import_type' => ['sometimes', 'string', 'max:32'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->imports->listImports($this->user($request), $filters)->through(fn (DataImport $i): array => $this->present($i)));
    }

    /**
     * Multipart body: import_type, file (CSV or XLSX, heading row first), mode? (create | update | upsert; stock: adjust | set)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'import_type' => ['required', 'string', 'max:32'],
            'file' => ['required', 'file'],
            'mode' => ['sometimes', 'string', 'max:16'],
        ]);

        $import = $this->imports->request($validated['import_type'], $request->file('file'), $validated['mode'] ?? null, $this->user($request));

        return APIResponse::accepted($this->present($import), 'Import queued. Follow its progress here; you will be notified when it finishes.');
    }

    public function show(Request $request, int $import): JsonResponse
    {
        return APIResponse::success($this->present($this->imports->getImport($this->user($request), $import)));
    }

    public function errors(Request $request, int $import): JsonResponse
    {
        $perPage = (int) ($request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:200']])['per_page'] ?? 50);

        return APIResponse::success($this->imports->listErrors($this->imports->getImport($this->user($request), $import), $perPage)
            ->through(static fn (DataImportError $e): array => ['row' => $e->row_number, 'field' => $e->field, 'message' => $e->message, 'value' => $e->value]));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DataImport $import): array
    {
        return [
            'id' => $import->id,
            'import_type' => $import->import_type,
            'mode' => $import->mode,
            'status' => $import->status,
            'file_name' => $import->original_filename,
            'processed_rows' => $import->processed_rows,
            'created' => $import->created_count,
            'updated' => $import->updated_count,
            'failed' => $import->failed_count,
            'error' => $import->error,
            'created_at' => $import->created_at->toIso8601String(),
            'started_at' => $import->started_at?->toIso8601String(),
            'completed_at' => $import->completed_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
