<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $modifier_group_id
 * @property string $name
 * @property string $price_adjustment base currency
 * @property bool $is_active
 * @property-read ModifierGroup $group
 */
class ModifierOption extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['modifier_group_id' => 'integer', 'price_adjustment' => 'decimal:4', 'is_active' => 'boolean'];

    /**
     * @return BelongsTo<ModifierGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }
}
