<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's saved address (spec §26.2). Orders snapshot addresses and
 * never reference these rows.
 *
 * @property int $id
 * @property int $customer_id
 * @property string|null $label
 * @property string $recipient_name
 * @property string|null $phone
 * @property string $address_line_1
 * @property string|null $address_line_2
 * @property int|null $city_id
 * @property int|null $state_id
 * @property int $country_id
 * @property string|null $postal_code
 * @property bool $is_default
 */
class Address extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'label', 'recipient_name', 'phone', 'address_line_1', 'address_line_2', 'city_id', 'state_id', 'country_id', 'postal_code',
    ];

    protected $casts = [
        'city_id' => 'integer',
        'state_id' => 'integer',
        'country_id' => 'integer',
        'is_default' => 'boolean',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
