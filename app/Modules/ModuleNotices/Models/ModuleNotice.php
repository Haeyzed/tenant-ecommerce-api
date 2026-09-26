<?php

declare(strict_types=1);

namespace App\Modules\ModuleNotices\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * An update, maintenance or info notice for one module (spec §18.1).
 *
 * @property int $id
 * @property string $module_key
 * @property string|null $tenant_id null = platform-wide
 * @property string $type update | maintenance | info
 * @property string|null $behavior hard_block | read_only; null = platform default
 * @property string $title
 * @property string $message
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 */
class ModuleNotice extends Model implements Auditable
{
    use AuditsToLandlord;

    public const array TYPES = ['update', 'maintenance', 'info'];

    /**
     * Core commerce is not a registry module but can still be announced or
     * put in maintenance (Part 6 intro, §18): its routes carry
     * module.notice:core.
     */
    public const string CORE = 'core';

    public const array BEHAVIORS = ['hard_block', 'read_only'];

    protected $connection = 'landlord';

    protected $fillable = ['module_key', 'tenant_id', 'type', 'behavior', 'title', 'message', 'starts_at', 'ends_at', 'is_active'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Notices in force now for the tenant (spec §18.2).
     *
     * @param  Builder<self>  $query
     */
    public function scopeInForceFor(Builder $query, ?string $tenantId): void
    {
        $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where(static fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(static fn (Builder $q) => $q->whereNull('tenant_id')->when($tenantId !== null, static fn (Builder $q) => $q->orWhere('tenant_id', $tenantId)));
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
