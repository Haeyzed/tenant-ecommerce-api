<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A plan or price a platform coupon is limited to (spec §14.8).
 *
 * @property int $id
 * @property int $platform_coupon_id
 * @property string $target_type plan | plan_price
 * @property int $target_id
 */
class PlatformCouponTarget extends Model
{
    protected $connection = 'landlord';

    protected $fillable = ['platform_coupon_id', 'target_type', 'target_id'];

    protected $casts = ['target_id' => 'integer'];
}
