<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrDocumentType;
use App\Modules\Hr\Services\HrDocumentTypeService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DocumentTypeController extends Controller
{
    private const array FIELDS = ['name', 'requires_expiry_date', 'is_mandatory_at_onboarding', 'is_active'];

    public function __construct(
        private readonly HrDocumentTypeService $types,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->types->listDocumentTypes()->map(fn (HrDocumentType $t): array => $this->presenter->documentType($t))->all());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->presenter->documentType($this->types->createDocumentType($request->only(self::FIELDS))), 'Document type created');
    }

    public function update(Request $request, HrDocumentType $documentType): JsonResponse
    {
        return APIResponse::success($this->presenter->documentType($this->types->updateDocumentType($documentType, $request->only(self::FIELDS))), 'Document type updated');
    }

    public function destroy(HrDocumentType $documentType): JsonResponse
    {
        $this->types->deleteDocumentType($documentType);

        return APIResponse::success(null, 'Document type deleted');
    }
}
