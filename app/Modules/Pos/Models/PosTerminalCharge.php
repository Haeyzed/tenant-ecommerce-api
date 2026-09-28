<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card-terminal charge, initiated and verified before the sale is
 * submitted (spec §51.4, A-35). A successful charge pays one sale: it is
 * linked to its order_payments row when the sale commits.
 *
 * @property int $id
 * @property int $pos_register_id
 * @property string $provider
 * @property string $reference ours; sent to the provider
 * @property string|null $provider_reference
 * @property string $amount
 * @property string $currency_code
 * @property string $status
 * @property string|null $card_last4
 * @property string|null $failure_reason
 * @property int|null $order_payment_id
 * @property int|null $initiated_by_user_id
 * @property-read PosRegister $register
 */
class PosTerminalCharge extends Model
{
    public const string PENDING = 'pending';

    public const string SUCCESSFUL = 'successful';

    public const string FAILED = 'failed';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'pos_register_id' => 'integer',
        'amount' => 'decimal:4',
        'order_payment_id' => 'integer',
        'initiated_by_user_id' => 'integer',
    ];

    /**
     * @return BelongsTo<PosRegister, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }
}
