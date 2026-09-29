<?php

declare(strict_types=1);

namespace App\Modules\Exports\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Access\Models\PlatformUser;
use App\Modules\Exports\Models\PlatformExport;
use App\Modules\Exports\Services\PlatformExportService;
use App\Modules\Exports\Support\ExportFileResponder;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform exports (D-134). A platform user sees and downloads only their
 * own exports; each type checks its own permission.
 */
final class PlatformExportController extends Controller
{
    public function __construct(private readonly PlatformExportService $exports) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) ($request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']])['per_page'] ?? 25);

        return APIResponse::success($this->exports->listFor($this->user($request), $perPage)->through(fn (PlatformExport $e): array => $this->present($e)));
    }

    /**
     * Body: export_type (tenants | subscriptions | payment_transactions | affiliates | affiliate_payouts), format (csv | xlsx | json), parameters?
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'export_type' => ['required', 'string', 'max:64'],
            'format' => ['required', 'string', 'in:csv,xlsx,json'],
            'parameters' => ['sometimes', 'array'],
        ]);

        $export = $this->exports->request($validated['export_type'], $validated['parameters'] ?? [], $validated['format'], $this->user($request));

        return APIResponse::accepted($this->present($export), 'Export queued');
    }

    public function show(Request $request, int $export): JsonResponse
    {
        return APIResponse::success($this->present($this->own($request, $export)));
    }

    public function download(Request $request, int $export, ExportFileResponder $files): Response
    {
        return $files->respond($this->own($request, $export));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlatformExport $export): array
    {
        return [
            'id' => $export->id,
            'export_type' => $export->export_type,
            'format' => $export->format,
            'status' => $export->status,
            'parameters' => $export->parameters,
            'row_count' => $export->row_count,
            'error' => $export->error,
            'created_at' => $export->created_at->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
        ];
    }

    private function own(Request $request, int $id): PlatformExport
    {
        return PlatformExport::query()->where('requested_by_id', $this->user($request)->id)->findOrFail($id);
    }

    private function user(Request $request): PlatformUser
    {
        /** @var PlatformUser */
        return $request->user();
    }
}
