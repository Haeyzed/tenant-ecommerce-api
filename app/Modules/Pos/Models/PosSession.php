<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cashier's shift on one register (spec §51.1): every sale in between is
 * tied to it, and closing it counts the drawer (§51.5).
 *
 * @property int $id
 * @property int $pos_register_id
 * @property int $opened_by_user_id
 * @property int|null $closed_by_user_id
 * @property string $opening_cash_float
 * @property string|null $closing_cash_float
 * @property string|null $expected_cash
 * @property string|null $cash_variance
 * @property string $status
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property-read PosRegister $register
 * @property-read User $openedBy
 * @property-read User|null $closedBy
 */
class PosSession extends Model
{
    public const string OPEN = 'open';

    public const string CLOSED = 'closed';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'pos_register_id' => 'integer',
        'opened_by_user_id' => 'integer',
        'closed_by_user_id' => 'integer',
        'opening_cash_float' => 'decimal:4',
        'closing_cash_float' => 'decimal:4',
        'expected_cash' => 'decimal:4',
        'cash_variance' => 'decimal:4',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<PosRegister, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
