<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Money paid to (or, negative, refunded by) a supplier outside the platform
 * (spec §49.4). No gateway integration.
 *
 * @property int $id
 * @property int $supplier_id
 * @property int|null $purchase_order_id null = a bulk payment across orders
 * @property int|null $purchase_return_id set on refund rows
 * @property string|null $amount_due
 * @property string|null $amount_received
 * @property string $amount_paid negative = money refunded by the supplier
 * @property string|null $change_given
 * @property string $payment_method
 * @property int|null $account_id
 * @property string $currency_code
 * @property string|null $exchange_rate_used 1 payment currency = x base
 * @property Carbon $paid_at
 * @property string|null $reference
 * @property string|null $notes
 * @property int $recorded_by_user_id
 * @property-read Supplier $supplier
 * @property-read PurchaseOrder|null $purchaseOrder
 */
class SupplierPayment extends Model implements AuditableContract
{
    use Auditable;

    public const array METHODS = ['cash', 'bank_transfer', 'cheque', 'card', 'other'];

    protected $connection = 'tenant';

    protected $fillable = ['amount_due', 'amount_received', 'amount_paid', 'change_given', 'payment_method', 'account_id', 'paid_at', 'reference', 'notes'];

    protected $casts = [
        'supplier_id' => 'integer',
        'purchase_order_id' => 'integer',
        'purchase_return_id' => 'integer',
        'account_id' => 'integer',
        'recorded_by_user_id' => 'integer',
        'amount_due' => 'decimal:4',
        'amount_received' => 'decimal:4',
        'amount_paid' => 'decimal:4',
        'change_given' => 'decimal:4',
        'exchange_rate_used' => 'decimal:12',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
