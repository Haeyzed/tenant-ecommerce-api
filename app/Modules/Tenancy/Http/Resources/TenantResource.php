<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Platform view of a tenant. Database coordinates are never exposed.
 *
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'owner_name' => $this->owner_name,
            'email' => $this->email,
            'status' => $this->status->value,
            'status_reason' => $this->status_reason,
            'country_id' => $this->country_id,
            'default_currency' => $this->default_currency,
            'timezone' => $this->timezone,
            'database_server_id' => $this->database_server_id,
            'schema_version' => $this->schema_version,
            'permissions_version' => $this->permissions_version,
            'trial_consumed_at' => $this->trial_consumed_at?->toIso8601String(),
            'provisioned_at' => $this->provisioned_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'purge_after' => $this->purge_after?->toIso8601String(),
            'purged_at' => $this->purged_at?->toIso8601String(),
            'domains' => $this->whenLoaded('domains', fn () => $this->domains->map(static fn (Domain $d): array => [
                'id' => $d->id,
                'domain' => $d->domain,
                'type' => $d->type,
                'is_primary' => $d->is_primary,
                'status' => $d->status->value,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
