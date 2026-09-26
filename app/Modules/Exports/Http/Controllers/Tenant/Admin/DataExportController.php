<?php

declare(strict_types=1);

namespace App\Modules\Exports\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Exports\Models\DataExport;
use App\Modules\Exports\Services\DataExportService;
use App\Modules\Exports\Support\ExportFileResponder;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The requesting staff user's exports (spec §19.4). Always available; each
 * type checks its own permission and feature.
 */
final class DataExportController extends Controller
{
    public function __construct(private readonly DataExportService $exports) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->own($request)
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))))
            ->through(fn (DataExport $e): array => $this->present($e));

        return APIResponse::success($page);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'export_type' => ['required', 'string', 'max:64'],
            'parameters' => ['sometimes', 'array'],
            'format' => ['required', 'string', 'in:csv,json,pdf'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $export = $this->exports->request($validated['export_type'], $validated['parameters'] ?? [], $validated['format'], $user);

        return APIResponse::accepted($this->present($export), 'The export is being prepared');
    }

    public function show(Request $request, int $export): JsonResponse
    {
        return APIResponse::success($this->present($this->own($request)->findOrFail($export)));
    }

    public function download(Request $request, int $export, ExportFileResponder $responder): Response
    {
        return $responder->respond($this->own($request)->findOrFail($export));
    }

    /**
     * @return Builder<DataExport>
     */
    private function own(Request $request): Builder
    {
        /** @var User $user */
        $user = $request->user();

        return DataExport::query()->where('requested_by_type', $user->getMorphClass())->where('requested_by_id', $user->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DataExport $export): array
    {
        return [
            'id' => $export->id,
            'export_type' => $export->export_type,
            'parameters' => $export->parameters,
            'format' => $export->format,
            'status' => $export->status,
            'row_count' => $export->row_count,
            'error' => $export->status === DataExport::FAILED ? 'The export could not be generated.' : null,
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'created_at' => $export->created_at?->toIso8601String(),
        ];
    }
}
