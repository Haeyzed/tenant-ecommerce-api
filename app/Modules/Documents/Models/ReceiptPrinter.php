<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A warehouse's ESC/POS receipt printer (spec §43.6). The POS client
 * delivers payloads to the device (Assumption A-28).
 *
 * @property int $id
 * @property string $name
 * @property int $warehouse_id
 * @property string $connection_type network | usb | bluetooth
 * @property string $capability_profile
 * @property int $characters_per_line
 * @property string|null $ip_address
 * @property int|null $port
 * @property bool $is_active
 * @property-read Warehouse $warehouse
 */
class ReceiptPrinter extends Model
{
    public const array CONNECTIONS = ['network', 'usb', 'bluetooth'];

    protected $connection = 'tenant';

    protected $fillable = ['name', 'warehouse_id', 'connection_type', 'capability_profile', 'characters_per_line', 'ip_address', 'port'];

    protected $casts = ['warehouse_id' => 'integer', 'characters_per_line' => 'integer', 'port' => 'integer', 'is_active' => 'boolean'];

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
