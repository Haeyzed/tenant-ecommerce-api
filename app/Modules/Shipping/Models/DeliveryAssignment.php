<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * The current in-house driver of a shipment and its status trail (spec
 * §36.2). Proof of delivery is kept privately.
 *
 * @property int $id
 * @property int $shipment_id
 * @property int $driver_id
 * @property string $status assigned | picked_up | en_route | delivered | failed
 * @property Carbon $assigned_at
 * @property Carbon|null $picked_up_at
 * @property Carbon|null $delivered_at
 * @property string|null $delivery_notes
 * @property-read Shipment $shipment
 * @property-read Driver $driver
 */
class DeliveryAssignment extends Model implements AuditableContract, HasMedia
{
    use Auditable;
    use InteractsWithMedia;

    public const string ASSIGNED = 'assigned';

    public const string PICKED_UP = 'picked_up';

    public const string EN_ROUTE = 'en_route';

    public const string DELIVERED = 'delivered';

    public const string FAILED = 'failed';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'shipment_id' => 'integer',
        'driver_id' => 'integer',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('proof_of_delivery')->singleFile()->useDisk('local');
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
