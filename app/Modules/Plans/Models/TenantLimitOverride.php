<?php

declare(strict_types=1);

namespace App\Modules\Plans\Models;

use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A tenant-specific replacement of one plan limit (spec §11.8).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $limit_key
 * @property int|null $limit_value
 * @property string|null $extra_amount
 * @property string|null $extra_currency_code
 * @property bool $billed
 * @property bool $is_active
 * @property Carbon|null $expires_at
 * @property string|null $reason
 */
class TenantLimitOverride extends Model implements Auditable
{
    use AuditsToLandlord;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id', 'limit_key', 'limit_value', 'extra_amount', 'extra_currency_code',
        'billed', 'is_active', 'expires_at', 'reason', 'overridden_by',
    ];

    protected $casts = [
        'limit_value' => 'integer',
        'extra_amount' => 'decimal:4',
        'billed' => 'boolean',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function isInForce(): bool
    {
        return $this->is_active && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
