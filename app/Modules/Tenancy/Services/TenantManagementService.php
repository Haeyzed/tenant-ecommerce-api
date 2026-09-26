<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Jobs\ExportTenant;
use App\Modules\Tenancy\Jobs\PurgeTenant;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Platform-side tenant lifecycle (spec §6.7, §7.3). Every step is written
 * to the landlord activity log with the acting platform user.
 */
final readonly class TenantManagementService
{
    public function __construct(
        private FeatureAccessService $features,
        private PlanLimitService $limits,
        private PlatformSettingsService $settings,
    ) {}

    /**
     * @param  array{status?: string|null, plan?: string|null, country?: int|null, search?: string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function listTenants(array $filters): LengthAwarePaginator
    {
        return Tenant::query()
            ->with(['domains' => static fn ($q) => $q->where('is_primary', true)])
            ->when($filters['status'] ?? null, static fn ($q, $v) => $q->where('status', $v))
            ->when($filters['country'] ?? null, static fn ($q, $v) => $q->where('country_id', $v))
            ->when($filters['plan'] ?? null, static fn ($q, $v) => $q->whereIn('id', Subscription::query()->select('tenant_id')
                ->where('status', '!=', 'cancelled')
                ->whereHas('plan', static fn ($p) => $p->where('slug', $v))))
            ->when($filters['search'] ?? null, static function ($q, string $v): void {
                $like = '%'.addcslashes($v, '%_\\').'%';
                $q->where(static fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('slug', 'like', $like));
            })
            ->orderByDesc('created_at')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
    }

    /**
     * @return array<string, mixed>
     */
    public function getTenant(Tenant $tenant): array
    {
        $usage = $tenant->provisioned_at !== null && $tenant->status !== TenantStatus::Purged
            ? $this->limits->getUsageSummary($tenant)
            : null;

        return [
            'tenant' => $tenant->load('domains'),
            'subscription' => Subscription::governing((string) $tenant->id)?->load('plan'),
            'modules' => $this->features->listEffectiveModules($tenant),
            'usage' => $usage,
        ];
    }

    public function suspendTenant(Tenant $tenant, ?string $reason, PlatformUser $by): Tenant
    {
        $this->assertStatus($tenant, [TenantStatus::Active], TenantStatus::Suspended);

        $tenant->forceFill(['status' => TenantStatus::Suspended, 'suspended_at' => now(), 'status_reason' => $reason])->save();
        ActivityRecorder::landlord('tenants', 'Tenant suspended', $tenant, ['reason' => $reason], $by);

        return $tenant;
    }

    public function reactivateTenant(Tenant $tenant, PlatformUser $by): Tenant
    {
        $this->assertStatus($tenant, [TenantStatus::Suspended], TenantStatus::Active);

        $tenant->forceFill(['status' => TenantStatus::Active, 'suspended_at' => null, 'status_reason' => null])->save();
        ActivityRecorder::landlord('tenants', 'Tenant reactivated', $tenant, [], $by);

        return $tenant;
    }

    /**
     * Revokes every tenant token; data is kept until purge_after.
     */
    public function closeTenant(Tenant $tenant, string $reason, ?PlatformUser $by = null): Tenant
    {
        $this->assertStatus($tenant, [TenantStatus::Active, TenantStatus::Suspended, TenantStatus::AwaitingPayment, TenantStatus::ProvisioningFailed], TenantStatus::Closed);

        $tenant->forceFill([
            'status' => TenantStatus::Closed,
            'status_reason' => $reason,
            'closed_at' => now(),
            'purge_after' => now()->addDays((int) $this->settings->get('tenant_retention_days', 90)),
        ])->save();

        if ($tenant->provisioned_at !== null) {
            $tenant->run(static function (): void {
                DB::connection('tenant')->table('personal_access_tokens')->delete();
            });
        }

        ActivityRecorder::landlord('tenants', 'Tenant closed', $tenant, ['reason' => $reason], $by);

        return $tenant;
    }

    /**
     * Nothing was deleted, so nothing is re-created.
     */
    public function restoreTenant(Tenant $tenant, PlatformUser $by): Tenant
    {
        $this->assertStatus($tenant, [TenantStatus::Closed], TenantStatus::Active);

        if ($tenant->provisioned_at === null) {
            throw ApiException::unprocessable('tenant_never_provisioned', 'This tenant never had a store to restore.');
        }

        $tenant->forceFill(['status' => TenantStatus::Active, 'closed_at' => null, 'purge_after' => null, 'status_reason' => null])->save();
        ActivityRecorder::landlord('tenants', 'Tenant restored', $tenant, [], $by);

        return $tenant;
    }

    public function exportTenant(Tenant $tenant, PlatformUser $requestedBy): void
    {
        if (! in_array($tenant->status, [TenantStatus::Active, TenantStatus::Suspended, TenantStatus::Closed], true) || $tenant->provisioned_at === null) {
            throw ApiException::unprocessable('tenant_not_exportable', 'Only a provisioned active, suspended or closed tenant can be exported.');
        }

        ExportTenant::dispatch((string) $tenant->id, $requestedBy->id);
        ActivityRecorder::landlord('tenants', 'Tenant export requested', $tenant, [], $requestedBy);
    }

    /**
     * Daily: closed tenants past purge_after (§6.7).
     */
    public function schedulePurges(): int
    {
        $count = 0;

        Tenant::query()
            ->where('status', TenantStatus::Closed->value)
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->chunkById(100, static function ($tenants) use (&$count): void {
                foreach ($tenants as $tenant) {
                    PurgeTenant::dispatch((string) $tenant->id);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @param  list<TenantStatus>  $from
     */
    private function assertStatus(Tenant $tenant, array $from, TenantStatus $to): void
    {
        if (! in_array($tenant->status, $from, true)) {
            throw ApiException::invalidTransition($tenant->status->value, $to->value);
        }
    }
}
