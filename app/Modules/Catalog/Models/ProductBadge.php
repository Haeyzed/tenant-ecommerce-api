<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A manually assigned badge (spec §29.5).
 *
 * @property int $id
 * @property int $product_id
 * @property string $badge_type new | bestseller | on_sale | limited | custom
 * @property string|null $label
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 */
class ProductBadge extends Model
{
    public const array TYPES = ['new', 'bestseller', 'on_sale', 'limited', 'custom'];

    protected $connection = 'tenant';

    protected $fillable = ['product_id', 'badge_type', 'label', 'starts_at', 'ends_at'];

    protected $casts = ['product_id' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    public function isLive(): bool
    {
        return ($this->starts_at === null || $this->starts_at->isPast()) && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
