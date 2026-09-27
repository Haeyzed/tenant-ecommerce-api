<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Someone the store buys stock from (spec §49.1). Notifiable by email only
 * (quotation requests); suppliers have no login.
 *
 * @property int $id
 * @property string $name
 * @property string|null $contact_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address_line
 * @property int|null $country_id
 * @property int|null $state_id
 * @property int|null $city_id
 * @property string|null $payment_terms
 * @property bool $is_active
 */
class Supplier extends Model implements AuditableContract
{
    use Auditable;
    use Notifiable;
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'contact_name', 'email', 'phone', 'address_line', 'country_id', 'state_id', 'city_id', 'payment_terms'];

    protected $casts = ['is_active' => 'boolean', 'country_id' => 'integer', 'state_id' => 'integer', 'city_id' => 'integer'];

    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    /**
     * @return HasMany<SupplierProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
