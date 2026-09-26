<?php

declare(strict_types=1);

namespace App\Modules\Legal\Http\Resources;

use App\Modules\Legal\Models\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LegalDocument
 */
final class LegalDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'version' => $this->version,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
            'effective_at' => $this->effective_at?->toIso8601String(),
            'required_at_registration' => $this->required_at_registration,
            'requires_reacceptance' => $this->requires_reacceptance,
        ];
    }
}
