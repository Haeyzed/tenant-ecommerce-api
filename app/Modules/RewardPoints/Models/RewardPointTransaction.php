<?php

declare(strict_types=1);

namespace App\Modules\RewardPoints\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One movement of a customer's points (spec §54.1): positive earned or
 * adjusted up, negative redeemed, expired or adjusted down.
 *
 * @property int $id
 * @property int $customer_id
 * @property int|null $order_id
 * @property string $type earned | redeemed | expired | adjusted
 * @property int $points
 * @property int $balance_after
 * @property Carbon|null $expires_at
 * @property bool $expiry_processed
 * @property string|null $notes
 * @property Carbon|null $created_at
 */
class RewardPointTransaction extends Model
{
    public const UPDATED_AT = null;

    public const string EARNED = 'earned';

    public const string REDEEMED = 'redeemed';

    public const string EXPIRED = 'expired';

    public const string ADJUSTED = 'adjusted';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'customer_id' => 'integer',
        'order_id' => 'integer',
        'points' => 'integer',
        'balance_after' => 'integer',
        'expires_at' => 'datetime',
        'expiry_processed' => 'boolean',
    ];
}
