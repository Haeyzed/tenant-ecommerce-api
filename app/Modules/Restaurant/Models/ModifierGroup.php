<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable set of menu options (spec §65.2), e.g. "Spice level".
 *
 * @property int $id
 * @property string $name
 * @property string $selection_type single | multiple
 * @property bool $is_required
 * @property-read Collection<int, ModifierOption> $options
 */
class ModifierGroup extends Model
{
    public const array SELECTION_TYPES = ['single', 'multiple'];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['is_required' => 'boolean'];

    /**
     * @return HasMany<ModifierOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class)->orderBy('id');
    }
}
