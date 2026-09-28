<?php

declare(strict_types=1);

namespace App\Modules\Installments\Models;

use App\Modules\Orders\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The store's own payment plan for one order (spec §47.1).
 *
 * @property int $id
 * @property int $order_id
 * @property string $total_amount
 * @property string $currency_code
 * @property int $number_of_installments
 * @property string $frequency weekly | biweekly | monthly
 * @property string $status active | completed | defaulted | cancelled
 * @property Carbon $starts_at
 * @property string|null $payment_provider
 * @property string|null $authorization_token encrypted
 * @property int $consecutive_overdue_count
 * @property-read Order $order
 * @property-read Collection<int, InstallmentPayment> $payments
 */
class InstallmentPlan extends Model
{
    public const string ACTIVE = 'active';

    public const string COMPLETED = 'completed';

    public const string DEFAULTED = 'defaulted';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::ACTIVE, self::COMPLETED, self::DEFAULTED, self::CANCELLED];

    public const array FREQUENCIES = ['weekly', 'biweekly', 'monthly'];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $hidden = ['authorization_token'];

    protected $casts = [
        'order_id' => 'integer',
        'total_amount' => 'decimal:4',
        'number_of_installments' => 'integer',
        'starts_at' => 'date',
        'authorization_token' => 'encrypted',
        'consecutive_overdue_count' => 'integer',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * @return HasMany<InstallmentPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class)->orderBy('sequence');
    }
}
