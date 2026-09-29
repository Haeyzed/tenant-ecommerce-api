<?php

declare(strict_types=1);

namespace App\Modules\SalesQuotations\Models;

use App\Modules\Customers\Models\Customer;
use App\Modules\SalesAgents\Models\SalesAgent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A request for a price quote (spec §53.1), by a customer or by staff on
 * the customer's behalf.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $sales_agent_id
 * @property string $status
 * @property string $currency_code
 * @property string|null $notes
 * @property Carbon $requested_at
 * @property int|null $created_by_user_id
 * @property-read Customer|null $customer
 * @property-read SalesAgent|null $salesAgent
 * @property-read Collection<int, SalesQuotationRequestItem> $items
 * @property-read SalesQuotation|null $quotation
 */
class SalesQuotationRequest extends Model
{
    public const string DRAFT = 'draft';

    public const string SENT = 'sent';

    public const string QUOTED = 'quoted';

    public const string ACCEPTED = 'accepted';

    public const string REJECTED = 'rejected';

    public const string EXPIRED = 'expired';

    public const string CONVERTED = 'converted';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::DRAFT, self::SENT, self::QUOTED, self::ACCEPTED, self::REJECTED, self::EXPIRED, self::CONVERTED, self::CANCELLED];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'customer_id' => 'integer',
        'sales_agent_id' => 'integer',
        'created_by_user_id' => 'integer',
        'requested_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<SalesAgent, $this>
     */
    public function salesAgent(): BelongsTo
    {
        return $this->belongsTo(SalesAgent::class);
    }

    /**
     * @return HasMany<SalesQuotationRequestItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesQuotationRequestItem::class)->orderBy('id');
    }

    /**
     * @return HasOne<SalesQuotation, $this>
     */
    public function quotation(): HasOne
    {
        return $this->hasOne(SalesQuotation::class);
    }
}
