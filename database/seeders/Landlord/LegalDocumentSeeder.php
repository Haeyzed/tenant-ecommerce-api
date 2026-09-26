<?php

declare(strict_types=1);

namespace Database\Seeders\Landlord;

use App\Modules\Legal\Models\LegalDocument;
use Illuminate\Database\Seeder;

/**
 * One draft per document type (spec §7.6). The platform must write and
 * publish its own legal text: registration stays closed until the types
 * required at registration are published (§9.2).
 */
final class LegalDocumentSeeder extends Seeder
{
    private const array REQUIRED_AT_REGISTRATION = ['terms_of_service', 'privacy_policy'];

    public function run(): void
    {
        foreach (LegalDocument::TYPES as $type) {
            if (LegalDocument::query()->where('document_type', $type)->exists()) {
                continue;
            }

            LegalDocument::query()->create([
                'document_type' => $type,
                'version' => 'draft-1',
                'title' => ucwords(str_replace('_', ' ', $type)),
                'body' => 'Draft. Replace this text with the platform\'s own '.str_replace('_', ' ', $type).' before publishing.',
                'status' => LegalDocument::DRAFT,
                'required_at_registration' => in_array($type, self::REQUIRED_AT_REGISTRATION, true),
                'requires_reacceptance' => false,
            ]);
        }
    }
}
