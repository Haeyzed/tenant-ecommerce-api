<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrDocumentType;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrEmployeeDocument;
use App\Modules\Hr\Services\HrEmployeeService;
use App\Shared\Http\APIResponse;
use App\Shared\Media\PrivateFileResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Employee documents (spec §58.2), on the private disk. The download route
 * is an addition: the files are never served from a public URL.
 */
final class EmployeeDocumentController extends Controller
{
    public function __construct(
        private readonly HrEmployeeService $employees,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(HrEmployee $employee): JsonResponse
    {
        return APIResponse::success($this->employees->listDocuments($employee)->map(fn (HrEmployeeDocument $d): array => $this->presenter->document($d))->all());
    }

    /**
     * Multipart: file, document_type_id, expiry_date? (required when the type requires one), notes?
     */
    public function store(Request $request, HrEmployee $employee): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file'],
            'document_type_id' => ['required', 'integer'],
            'expiry_date' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);
        $type = HrDocumentType::query()->findOrFail((int) $validated['document_type_id']);
        /** @var UploadedFile $file */
        $file = $validated['file'];

        $document = $this->employees->attachDocument($employee, $file, $type, $validated['expiry_date'] ?? null, $validated['notes'] ?? null);

        return APIResponse::created($this->presenter->document($document->load('documentType')), 'Document uploaded');
    }

    public function download(HrEmployee $employee, HrEmployeeDocument $document, PrivateFileResponder $files): Response
    {
        return $files->respond($this->own($employee, $document)->getFirstMedia('file'));
    }

    public function destroy(HrEmployee $employee, HrEmployeeDocument $document): JsonResponse
    {
        $this->employees->deleteDocument($this->own($employee, $document));

        return APIResponse::success(null, 'Document deleted');
    }

    /**
     * Query: within_days? (default 30). Passed expiries are included.
     */
    public function expiring(Request $request): JsonResponse
    {
        $days = (int) ($request->validate(['within_days' => ['sometimes', 'integer', 'min:0', 'max:365']])['within_days'] ?? 30);

        return APIResponse::success($this->employees->getExpiringDocuments($days)->map(fn (HrEmployeeDocument $d): array => $this->presenter->document($d))->all());
    }

    private function own(HrEmployee $employee, HrEmployeeDocument $document): HrEmployeeDocument
    {
        return $document->employee_id === $employee->id ? $document : throw new NotFoundHttpException;
    }
}
