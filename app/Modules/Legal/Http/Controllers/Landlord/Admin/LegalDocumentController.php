<?php

declare(strict_types=1);

namespace App\Modules\Legal\Http\Controllers\Landlord\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Http\Resources\LegalDocumentResource;
use App\Modules\Legal\Models\LegalDocument;
use App\Modules\Legal\Services\LegalDocumentService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LegalDocumentController extends Controller
{
    public function __construct(private readonly LegalDocumentService $legal) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'document_type' => ['sometimes', 'string'],
            'status' => ['sometimes', 'in:draft,published,retired'],
        ]);

        return APIResponse::success(LegalDocumentResource::collection(LegalDocument::query()
            ->when($filters['document_type'] ?? null, static fn ($q, $v) => $q->where('document_type', $v))
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->orderBy('document_type')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))))));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(new LegalDocumentResource($this->legal->createDraft($request->all())));
    }

    public function update(Request $request, LegalDocument $document): JsonResponse
    {
        return APIResponse::success(new LegalDocumentResource($this->legal->updateDraft($document, $request->all())), 'Draft updated');
    }

    public function publish(LegalDocument $document): JsonResponse
    {
        return APIResponse::success(new LegalDocumentResource($this->legal->publish($document)), 'Document published');
    }
}
