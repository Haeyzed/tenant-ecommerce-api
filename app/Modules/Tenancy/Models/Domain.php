<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Enums\DomainStatus;
use App\Modules\Tenancy\Support\HostTenantResolver;
use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/**
 * A host name that resolves to a tenant (spec §7.2, §7.5).
 *
 * @property int $id
 * @property string $domain
 * @property string $tenant_id
 * @property string $type
 * @property bool $is_primary
 * @property DomainStatus $status
 */
class Domain extends BaseDomain
{
    protected $connection = 'landlord';

    protected $hidden = ['verification_token'];

    protected $casts = [
        'is_primary' => 'boolean',
        'status' => DomainStatus::class,
        'verified_at' => 'datetime',
        'tls_checked_at' => 'datetime',
        'tls_expires_at' => 'datetime',
        'last_dns_check_at' => 'datetime',
        'dns_check_result' => 'array',
    ];

    /**
     * A changed or removed host must stop (or start) resolving at once, not
     * after the resolver cache TTL.
     */
    protected static function booted(): void
    {
        $forget = static function (self $domain): void {
            HostTenantResolver::forgetHost($domain->domain);

            if ($domain->isDirty('domain') || $domain->wasChanged('domain')) {
                HostTenantResolver::forgetHost((string) $domain->getOriginal('domain'));
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    public function isCustom(): bool
    {
        return $this->type === 'custom';
    }

    /**
     * Whether this host may identify its tenant (spec §7.5 rule 1).
     */
    public function identifiesTenant(): bool
    {
        if (! $this->isCustom()) {
            return true;
        }

        return $this->verified_at !== null
            && in_array($this->status, [DomainStatus::Verified, DomainStatus::Active, DomainStatus::Misconfigured], true);
    }
}
