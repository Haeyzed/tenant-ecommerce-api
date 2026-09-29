<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\Hr\Models\HrDocumentType;
use App\Modules\Hr\Models\HrEmployeeDocument;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Employee document types (spec §58.2, §58.8): a managed lookup.
 */
final readonly class HrDocumentTypeService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createDocumentType(array $data): HrDocumentType
    {
        $type = new HrDocumentType;
        $type->forceFill($this->validate($data, true))->save();

        return $type;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDocumentType(HrDocumentType $type, array $data): HrDocumentType
    {
        $type->forceFill($this->validate($data, false))->save();

        return $type;
    }

    /**
     * Blocked while documents use it: deactivate it instead.
     */
    public function deleteDocumentType(HrDocumentType $type): void
    {
        if (HrEmployeeDocument::query()->where('document_type_id', $type->id)->exists()) {
            throw ApiException::unprocessable('document_type_in_use', 'Documents of this type exist. Deactivate the type instead.');
        }

        $type->delete();
    }

    /**
     * @return Collection<int, HrDocumentType>
     */
    public function listDocumentTypes(bool $activeOnly = false): Collection
    {
        return HrDocumentType::query()->when($activeOnly, static fn ($q) => $q->where('is_active', true))->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        return Validator::make($data, [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'requires_expiry_date' => ['sometimes', 'boolean'],
            'is_mandatory_at_onboarding' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();
    }
}
