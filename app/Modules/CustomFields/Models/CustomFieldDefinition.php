<?php

declare(strict_types=1);

namespace App\Modules\CustomFields\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A tenant-defined attribute of a registered entity (spec §23.2). key,
 * field_type and entity_type are immutable once created.
 *
 * @property int $id
 * @property string $entity_type
 * @property string $key
 * @property string $label
 * @property string|null $description
 * @property string|null $placeholder
 * @property string $field_type
 * @property list<array{value: string, label: string}>|null $options
 * @property string|null $display
 * @property mixed $default_value
 * @property bool $is_required
 * @property bool $is_admin_only
 * @property bool $is_active
 * @property array<string, mixed>|null $validation
 * @property bool $show_in_table
 * @property bool $show_on_form
 * @property bool $show_on_detail
 * @property bool $show_on_documents
 * @property bool $is_searchable
 * @property bool $is_filterable
 * @property int $sort_order
 */
class CustomFieldDefinition extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'entity_type', 'key', 'label', 'description', 'placeholder', 'field_type', 'options', 'display', 'default_value',
        'is_required', 'is_admin_only', 'is_active', 'validation', 'show_in_table', 'show_on_form', 'show_on_detail',
        'show_on_documents', 'is_searchable', 'is_filterable', 'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'default_value' => 'json',
        'validation' => 'array',
        'is_required' => 'boolean',
        'is_admin_only' => 'boolean',
        'is_active' => 'boolean',
        'show_in_table' => 'boolean',
        'show_on_form' => 'boolean',
        'show_on_detail' => 'boolean',
        'show_on_documents' => 'boolean',
        'is_searchable' => 'boolean',
        'is_filterable' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return HasMany<CustomFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    /**
     * @return list<string>
     */
    public function optionValues(): array
    {
        return array_values(array_map(static fn (array $o): string => (string) $o['value'], (array) $this->options));
    }
}
