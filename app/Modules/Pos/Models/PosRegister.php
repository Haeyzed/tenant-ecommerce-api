<?php

declare(strict_types=1);

namespace App\Modules\Pos\Models;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A till (spec §51.1). Stock is sold from, and deducted at, its warehouse.
 * terminal_credentials are secrets: encrypted, never serialised, never
 * audited.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property string $name
 * @property string|null $terminal_provider
 * @property array<string, string>|null $terminal_credentials
 * @property bool $is_active
 * @property-read Warehouse $warehouse
 */
class PosRegister extends Model implements AuditableContract
{
    use Auditable;

    public const array TERMINAL_PROVIDERS = ['moniepoint', 'opay', 'stripe_terminal'];

    protected $connection = 'tenant';

    protected $fillable = ['warehouse_id', 'name'];

    protected $hidden = ['terminal_credentials'];

    protected $casts = [
        'warehouse_id' => 'integer',
        'terminal_credentials' => 'encrypted:array',
        'is_active' => 'boolean',
    ];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['terminal_credentials'];

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
