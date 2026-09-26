<?php

declare(strict_types=1);

namespace App\Modules\Plans\Models;

use App\Modules\Plans\Enums\OverrideEffect;
use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A tenant-specific grant, revoke or suspension of one feature (spec §11.7).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $feature_key
 * @property OverrideEffect $effect
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property string|null $reason
 * @property string|null $extra_amount
 * @property string|null $extra_currency_code
 * @property bool $billed
 * @property int|null $overridden_by
 */
class TenantFeature extends Model implements Auditable
{
    use AuditsToLandlord;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id', 'feature_key', 'effect', 'starts_at', 'expires_at', 'reason',
        'extra_amount', 'extra_currency_code', 'billed', 'overridden_by',
    ];

    protected $casts = [
        'effect' => OverrideEffect::class,
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'extra_amount' => 'decimal:4',
        'billed' => 'boolean',
    ];

    /**
     * Overrides in force now: started, and not expired (spec §11.5).
     *
     * @param  Builder<self>  $query
     */
    public function scopeInForce(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
        })->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function isInForce(): bool
    {
        return ($this->starts_at === null || $this->starts_at->lte(now()))
            && ($this->expires_at === null || $this->expires_at->gt(now()));
    }
}
