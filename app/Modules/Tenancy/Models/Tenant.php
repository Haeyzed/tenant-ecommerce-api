<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Support\HostTenantResolver;
use App\Modules\Tenancy\Support\TenantDatabaseConfig;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\DatabaseConfig;

/**
 * A business registered on the platform (spec §7.2).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $owner_name
 * @property string $email
 * @property TenantStatus $status
 * @property string $timezone
 * @property int|null $database_server_id
 * @property int $country_id
 * @property string $default_currency
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;
    use Notifiable;

    protected $connection = 'landlord';

    protected $casts = [
        'status' => TenantStatus::class,
        'trial_consumed_at' => 'datetime',
        'provisioned_at' => 'datetime',
        'suspended_at' => 'datetime',
        'closed_at' => 'datetime',
        'purge_after' => 'datetime',
        'purged_at' => 'datetime',
    ];

    /**
     * Real columns; everything else goes to stancl's "data" JSON (spec §6.1).
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id', 'name', 'slug', 'owner_name', 'email', 'status', 'trial_consumed_at', 'timezone',
            'database_server_id', 'schema_version', 'country_id', 'default_currency', 'permissions_version',
            'provisioned_at', 'suspended_at', 'status_reason', 'closed_at', 'purge_after', 'purged_at',
            'created_at', 'updated_at',
        ];
    }

    /**
     * Status and other resolved attributes must reach the host resolver at
     * once: a suspension takes effect on the next request (spec §6.5).
     */
    protected static function booted(): void
    {
        $forget = static function (self $tenant): void {
            foreach ($tenant->domains()->pluck('domain') as $host) {
                HostTenantResolver::forgetHost((string) $host);
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    public function database(): DatabaseConfig
    {
        return new TenantDatabaseConfig($this);
    }

    /**
     * @return BelongsTo<DatabaseServer, $this>
     */
    public function databaseServer(): BelongsTo
    {
        return $this->belongsTo(DatabaseServer::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The one subscription that is not cancelled (spec §14.1).
     *
     * @return HasOne<Subscription, $this>
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->ofMany(['id' => 'max'], function ($query): void {
            $query->where('status', '!=', 'cancelled');
        });
    }

    public function primaryDomain(): ?Domain
    {
        /** @var Domain|null $domain */
        $domain = $this->domains()->where('is_primary', true)->first();

        return $domain;
    }

    public function isProvisioned(): bool
    {
        return $this->provisioned_at !== null;
    }
}
