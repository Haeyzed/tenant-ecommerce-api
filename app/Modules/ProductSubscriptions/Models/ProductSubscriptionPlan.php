<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Models;

use App\Modules\Catalog\Models\Product;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A delivery schedule offered for a subscribable product (spec §55.1),
 * e.g. monthly × 2 = every two months. Deactivating a plan stops new
 * subscriptions; existing ones keep renewing on it.
 *
 * @property int $id
 * @property int $product_id
 * @property string $interval
 * @property int $interval_count
 * @property bool $is_active
 * @property-read Product $product
 */
class ProductSubscriptionPlan extends Model implements AuditableContract
{
    use Auditable;

    public const array INTERVALS = ['weekly', 'biweekly', 'monthly', 'quarterly'];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'product_id' => 'integer',
        'interval_count' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * The date one period after $from. Months never overflow: a plan
     * started on 31 January renews on 28 or 29 February.
     */
    public function advance(CarbonInterface $from): CarbonImmutable
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $count = max(1, $this->interval_count);

        return match ($this->interval) {
            'weekly' => $from->addWeeks($count),
            'biweekly' => $from->addWeeks(2 * $count),
            'quarterly' => $from->addMonthsNoOverflow(3 * $count),
            default => $from->addMonthsNoOverflow($count),
        };
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
