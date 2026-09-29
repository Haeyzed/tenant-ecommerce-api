<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Google or Facebook identity linked to a customer of this store
 * (D-132). No provider token is kept.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $provider google | facebook
 * @property string $provider_user_id
 * @property string|null $provider_email
 * @property bool $provider_email_verified
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 * @property-read Customer $customer
 */
class CustomerSocialAccount extends Model
{
    public const array PROVIDERS = ['google', 'facebook'];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'customer_id' => 'integer',
        'provider_email_verified' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
