<?php

declare(strict_types=1);

namespace App\Modules\Legal\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Modules\Legal\Http\Resources\LegalDocumentResource;
use App\Modules\Legal\Models\LegalDocument;
use App\Modules\Legal\Services\LegalDocumentService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;

/**
 * Current legal documents for sign-up and the website (spec §9.8).
 */
final class LegalDocumentController extends Controller
{
    public function __construct(private readonly LegalDocumentService $legal) {}

    public function current(): JsonResponse
    {
        return APIResponse::success(LegalDocumentResource::collection($this->legal->allCurrent()));
    }

    public function show(string $type): JsonResponse
    {
        if (! in_array($type, LegalDocument::TYPES, true)) {
            abort(404);
        }

        $document = $this->legal->current($type) ?? abort(404);

        return APIResponse::success(new LegalDocumentResource($document));
    }
}
