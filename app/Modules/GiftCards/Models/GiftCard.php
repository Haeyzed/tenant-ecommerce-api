<?php

declare(strict_types=1);

namespace App\Modules\GiftCards\Models;

use App\Modules\Customers\Models\Customer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A stored-money instrument (spec §46.1). current_balance is the source of
 * truth; redemptions are its audit trail (initial_value − Σ amount).
 *
 * @property int $id
 * @property string $code
 * @property string $initial_value
 * @property string $current_balance
 * @property string $currency_code
 * @property int|null $purchased_by_customer_id
 * @property int|null $source_order_id
 * @property string|null $recipient_email
 * @property string|null $recipient_message
 * @property string $status
 * @property int|null $issued_by_user_id
 * @property Carbon $issued_at
 * @property Carbon|null $expires_at
 * @property-read Collection<int, GiftCardRedemption> $redemptions
 */
class GiftCard extends Model implements AuditableContract
{
    use Auditable;

    public const string ACTIVE = 'active';

    public const string REDEEMED = 'redeemed';

    public const string EXPIRED = 'expired';

    public const string DISABLED = 'disabled';

    public const array STATUSES = [self::ACTIVE, self::REDEEMED, self::EXPIRED, self::DISABLED];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'initial_value' => 'decimal:4',
        'current_balance' => 'decimal:4',
        'purchased_by_customer_id' => 'integer',
        'source_order_id' => 'integer',
        'issued_by_user_id' => 'integer',
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * The code is a bearer secret: never in audit rows.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['code'];

    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * The last four characters, for lists and receipts.
     */
    public function maskedCode(): string
    {
        return '…'.substr($this->code, -4);
    }

    /**
     * @return HasMany<GiftCardRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(GiftCardRedemption::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'purchased_by_customer_id');
    }
}
