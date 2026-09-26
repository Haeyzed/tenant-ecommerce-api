<?php

declare(strict_types=1);

namespace App\Modules\Plans\Models;

use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A commercial subscription tier (spec §11.6). Prices are child rows.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $tagline
 * @property bool $is_active
 * @property bool $is_public
 * @property bool $is_recommended
 * @property string|null $marketing_badge
 * @property int $sort_order
 */
class Plan extends Model implements Auditable
{
    use AuditsToLandlord;
    use HasFactory;

    protected $connection = 'landlord';

    protected $fillable = ['name', 'slug', 'description', 'tagline', 'is_active', 'is_public', 'is_recommended', 'marketing_badge', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'is_recommended' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return HasMany<PlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /**
     * @return HasMany<PlanFeature, $this>
     */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /**
     * @return HasMany<PlanLimit, $this>
     */
    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }
}
