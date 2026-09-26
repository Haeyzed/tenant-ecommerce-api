<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Http\Controllers\Landlord\Portal;

use App\Modules\Legal\Models\LegalAcceptance;
use App\Modules\Legal\Models\LegalDocument;
use App\Modules\Legal\Services\LegalDocumentService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Re-acceptance of a new affiliate agreement version (spec §21A.7, §9.2).
 */
final class LegalController extends PortalController
{
    public function __construct(private readonly LegalDocumentService $legal) {}

    public function pending(Request $request): JsonResponse
    {
        $document = $this->pendingAgreement($request);

        return APIResponse::success($document === null ? [] : [[
            'id' => $document->id,
            'document_type' => $document->document_type,
            'title' => $document->title,
            'version' => $document->version,
            'effective_at' => $document->effective_at?->toIso8601String(),
        ]]);
    }

    public function accept(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'accepted_legal_document_ids' => ['required', 'array', 'min:1'],
            'accepted_legal_document_ids.*' => ['integer'],
        ])['accepted_legal_document_ids'];

        $current = $this->legal->current('affiliate_agreement');

        if ($current === null || ! in_array($current->id, array_map('intval', $ids), true)) {
            throw ApiException::unprocessable('legal_version_outdated', 'Please accept the current affiliate agreement.');
        }

        $affiliate = $this->affiliate($request);

        if (! LegalAcceptance::query()->where('legal_document_id', $current->id)->where('affiliate_id', $affiliate->id)->exists()) {
            $this->legal->recordAcceptances([$current->id], ['affiliate_id' => $affiliate->id, 'name' => $affiliate->name, 'email' => $affiliate->email], 'reacceptance', $request);
        }

        return APIResponse::success(null, 'Agreement accepted');
    }

    private function pendingAgreement(Request $request): ?LegalDocument
    {
        $current = $this->legal->current('affiliate_agreement');

        if ($current === null || ! $current->requires_reacceptance) {
            return null;
        }

        $accepted = LegalAcceptance::query()->where('legal_document_id', $current->id)->where('affiliate_id', $this->affiliate($request)->id)->exists();

        return $accepted ? null : $current;
    }
}
