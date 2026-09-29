<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Exports\Http\Controllers\Tenant\Admin\DataExportController;
use App\Modules\Exports\Services\DataExportService;
use App\Modules\Reporting\Services\ReportService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Advanced reports (spec §61.2, §61.3). Every report route is show() with
 * the report key as a route default, so its permission derives as
 * reports.{key}.view. Row lists are paginated (page, per_page ≤ 200); the
 * summary covers the whole range.
 */
final class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function show(Request $request): JsonResponse
    {
        $key = (string) $request->route()?->defaults['report'];
        $paging = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:200']]);
        $filters = collect($request->query())->except(['page', 'per_page'])->all();
        $result = $this->reports->run($key, $filters, $this->user($request));
        $perPage = (int) ($paging['per_page'] ?? 50);
        $page = (int) ($paging['page'] ?? 1);
        $pagination = null;

        if ($result->pages !== null || $result->query !== null) {
            /** @var LengthAwarePaginator<int, object> $paginator */
            $paginator = $result->pages !== null ? ($result->pages)($page, $perPage) : $result->query->paginate($perPage, ['*'], 'page', $page);
            $rows = array_map(static fn (object $row): array => $result->map === null ? (array) $row : ($result->map)($row), $paginator->items());
            $pagination = ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()];
        } else {
            $rows = $result->rows ?? [];
        }

        return APIResponse::success([
            'report' => $key,
            'currency' => $this->reports->currencyCode(),
            'filters' => $filters,
            'summary' => $result->summary,
            'columns' => $result->columns,
            'rows' => $rows,
            'chart' => $result->chart,
            'notes' => $result->notes,
        ], meta: $pagination === null ? [] : ['pagination' => $pagination]);
    }

    /**
     * Body: filters (the report's own), format (csv | pdf). Answers 202
     * with the export; the file is ready when export.ready arrives (§19.4).
     */
    public function export(Request $request, string $reportKey, DataExportService $exports): JsonResponse
    {
        $validated = $request->validate([
            'filters' => ['sometimes', 'array'],
            'format' => ['required', Rule::in(['csv', 'pdf'])],
        ]);

        $export = $exports->request('report:'.$reportKey, (array) ($validated['filters'] ?? []), $validated['format'], $this->user($request));

        return APIResponse::accepted(DataExportController::present($export), 'The report export is being prepared');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
