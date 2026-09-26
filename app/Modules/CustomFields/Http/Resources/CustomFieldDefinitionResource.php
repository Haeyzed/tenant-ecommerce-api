<?php

declare(strict_types=1);

namespace App\Modules\CustomFields\Http\Resources;

use App\Modules\CustomFields\Models\CustomFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomFieldDefinition
 */
final class CustomFieldDefinitionResource extends JsonResource
{
    public bool $public = false;

    public ?int $valueCount = null;

    public function forPublic(): self
    {
        $this->public = true;

        return $this;
    }

    public function withValueCount(int $count): self
    {
        $this->valueCount = $count;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'placeholder' => $this->placeholder,
            'field_type' => $this->field_type,
            'options' => $this->options,
            'display' => $this->display,
            'default_value' => $this->default_value,
            'is_required' => $this->is_required,
            'validation' => $this->validation,
            'sort_order' => $this->sort_order,
            'is_admin_only' => $this->when(! $this->public, $this->is_admin_only),
            'is_active' => $this->when(! $this->public, $this->is_active),
            'show_in_table' => $this->when(! $this->public, $this->show_in_table),
            'show_on_form' => $this->when(! $this->public, $this->show_on_form),
            'show_on_detail' => $this->when(! $this->public, $this->show_on_detail),
            'show_on_documents' => $this->when(! $this->public, $this->show_on_documents),
            'is_searchable' => $this->when(! $this->public, $this->is_searchable),
            'is_filterable' => $this->when(! $this->public, $this->is_filterable),
            'value_count' => $this->when($this->valueCount !== null, $this->valueCount),
        ];
    }
}
