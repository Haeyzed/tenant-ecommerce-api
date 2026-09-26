<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A time-boxed sale price layer (spec §37.7).
 *
 * @property int $id
 * @property string $name
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property bool $is_active
 * @property-read Collection<int, FlashSaleProduct> $products
 */
class FlashSale extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'starts_at', 'ends_at', 'is_active'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function isRunning(): bool
    {
        return $this->is_active && $this->starts_at->lte(now()) && $this->ends_at->gt(now());
    }

    /**
     * @return HasMany<FlashSaleProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(FlashSaleProduct::class)->orderBy('id');
    }
}
