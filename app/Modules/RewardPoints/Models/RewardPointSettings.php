<?php

declare(strict_types=1);

namespace App\Modules\RewardPoints\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The loyalty programme's rules (spec §54.1), a singleton row. Earn and burn
 * rates are deliberately separate; amounts are in the base currency.
 *
 * @property int $id
 * @property bool $is_active
 * @property string $amount_per_point spend that earns one point
 * @property string|null $minimum_order_amount_to_earn
 * @property int|null $point_expiry_days
 * @property string $redeem_amount_per_point value of one point when redeeming
 * @property string|null $minimum_order_total_to_redeem
 * @property int|null $minimum_redeem_points
 * @property int|null $maximum_redeem_points_per_order
 */
class RewardPointSettings extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $table = 'reward_point_settings';

    protected $fillable = ['amount_per_point', 'minimum_order_amount_to_earn', 'point_expiry_days', 'redeem_amount_per_point',
        'minimum_order_total_to_redeem', 'minimum_redeem_points', 'maximum_redeem_points_per_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'amount_per_point' => 'decimal:4',
        'minimum_order_amount_to_earn' => 'decimal:4',
        'point_expiry_days' => 'integer',
        'redeem_amount_per_point' => 'decimal:4',
        'minimum_order_total_to_redeem' => 'decimal:4',
        'minimum_redeem_points' => 'integer',
        'maximum_redeem_points_per_order' => 'integer',
    ];
}
