<?php

declare(strict_types=1);

namespace App\Modules\ModuleNotices\Services;

use App\Modules\ModuleNotices\Models\ModuleNotice;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Module notices and maintenance (spec §18.3).
 */
final class ModuleNoticeService
{
    private const string CACHE_KEY = 'module-notices:active';

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly FeatureAccessService $features,
        private readonly PlatformSettingsService $settings,
        private readonly NotificationDispatchService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createNotice(array $data): ModuleNotice
    {
        /** @var ModuleNotice $notice */
        $notice = ModuleNotice::query()->create($this->validate($data));
        $this->flush();

        $this->notifyTenants($notice);

        return $notice;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateNotice(ModuleNotice $notice, array $data): ModuleNotice
    {
        $notice->fill($this->validate($data + $notice->only(['module_key', 'type', 'title', 'message', 'starts_at']), $notice))->save();
        $this->flush();

        return $notice;
    }

    public function deactivateNotice(ModuleNotice $notice): void
    {
        $notice->forceFill(['is_active' => false])->save();
        $this->flush();
    }

    /**
     * @return Collection<int, ModuleNotice>
     */
    public function getActiveNoticesForModule(string $moduleKey, ?Tenant $tenant = null): Collection
    {
        return $this->active()
            ->filter(static fn (ModuleNotice $notice): bool => $notice->module_key === $moduleKey
                && ($notice->tenant_id === null || $notice->tenant_id === $tenant?->getTenantKey()))
            ->values();
    }

    /**
     * @return Collection<int, ModuleNotice>
     */
    public function getActiveNoticesForTenant(Tenant $tenant, ?string $moduleKey = null): Collection
    {
        return $this->active()
            ->filter(static fn (ModuleNotice $notice): bool => ($notice->tenant_id === null || $notice->tenant_id === $tenant->getTenantKey())
                && ($moduleKey === null || $notice->module_key === $moduleKey))
            ->values();
    }

    public function effectiveBehavior(ModuleNotice $notice): string
    {
        return $notice->behavior ?? (string) $this->settings->get('default_maintenance_behavior', 'hard_block');
    }

    public function flush(): void
    {
        Cache::store('landlord')->forget(self::CACHE_KEY);
    }

    /**
     * Every notice in force now, across tenants. Few rows; cached for a
     * minute so starts_at and ends_at take effect within that window.
     *
     * @return Collection<int, ModuleNotice>
     */
    private function active(): Collection
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::store('landlord')->remember(self::CACHE_KEY, 60, static fn (): array => ModuleNotice::query()
            ->where('is_active', true)
            ->where(static fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderBy('starts_at')
            ->get()
            ->map(static fn (ModuleNotice $notice): array => $notice->getAttributes())
            ->all());

        return ModuleNotice::hydrate($rows)
            ->filter(static fn (ModuleNotice $notice): bool => $notice->starts_at->lte(now())
                && ($notice->ends_at === null || $notice->ends_at->isFuture()))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?ModuleNotice $existing = null): array
    {
        $validated = validator($data, [
            'module_key' => ['required', 'string', Rule::in(array_keys($this->registry->all()))],
            'tenant_id' => ['nullable', 'string', Rule::exists('landlord.tenants', 'id')],
            'type' => ['required', Rule::in(ModuleNotice::TYPES)],
            'behavior' => ['nullable', Rule::in(ModuleNotice::BEHAVIORS)],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        if (($validated['type'] ?? $existing?->type) !== 'maintenance' && ($validated['behavior'] ?? null) !== null) {
            throw ValidationException::withMessages(['behavior' => ['Only a maintenance notice has a behavior.']]);
        }

        return $validated;
    }

    /**
     * module_notice.published to every tenant with the module enabled (or
     * the one targeted tenant).
     */
    private function notifyTenants(ModuleNotice $notice): void
    {
        $query = Tenant::query()->where('status', TenantStatus::Active->value)
            ->when($notice->tenant_id !== null, static fn ($q) => $q->whereKey($notice->tenant_id));

        $variables = [
            'notice_title' => $notice->title,
            'notice_message' => $notice->message,
            'module_name' => $this->registry->get($notice->module_key)->name,
            'starts_at' => $notice->starts_at->toDayDateTimeString(),
            'ends_at' => $notice->ends_at?->toDayDateTimeString() ?? 'further notice',
        ];

        $query->chunkById(200, function ($tenants) use ($notice, $variables): void {
            foreach ($tenants as $tenant) {
                if ($this->features->tenantCanAccess($tenant, $notice->module_key)) {
                    $this->notifications->dispatch('module_notice.published', $tenant, $variables + ['owner_name' => $tenant->owner_name]);
                }
            }
        });
    }
}
