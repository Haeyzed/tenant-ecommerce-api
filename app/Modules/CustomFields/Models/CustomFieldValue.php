<?php

declare(strict_types=1);

namespace App\Modules\CustomFields\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One value of one custom field on one record (spec §23.2). Exactly one
 * value column is used, chosen by the field type.
 *
 * @property int $id
 * @property int $custom_field_definition_id
 * @property string $entity_type
 * @property int $entity_id
 * @property string|null $value_string
 * @property string|null $value_text
 * @property string|null $value_decimal
 * @property Carbon|null $value_date
 * @property Carbon|null $value_datetime
 * @property list<string>|null $value_json
 * @property-read CustomFieldDefinition $definition
 */
class CustomFieldValue extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'custom_field_definition_id', 'entity_type', 'entity_id', 'value_string', 'value_text', 'value_decimal',
        'value_date', 'value_datetime', 'value_json',
    ];

    protected $casts = [
        'entity_id' => 'integer',
        'value_date' => 'date',
        'value_datetime' => 'datetime',
        'value_json' => 'array',
    ];

    /**
     * @return BelongsTo<CustomFieldDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'custom_field_definition_id');
    }
}
