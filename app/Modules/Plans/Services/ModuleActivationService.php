<?php

declare(strict_types=1);

namespace App\Modules\Plans\Services;

use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Models\TenantModule;
use App\Modules\Plans\Support\ModuleDefinition;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of tenant_modules (spec Â§6.4, Â§11.7, Â§11.12). Rows live in
 * the landlord database; module hooks run in the tenant context.
 */
final class ModuleActivationService
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly FeatureAccessService $features,
        private readonly NotificationDispatchService $notifications,
    ) {}

    public function enable(Tenant $tenant, string $key, User $actor): void
    {
        $definition = $this->definition($key);
        $state = $this->features->state($tenant, $key);

        $this->assertChangeable($key, $state);

        $row = $this->row($tenant, $key);

        if ($row?->status === TenantModule::ENABLED) {
            return;
        }

        $missing = array_values(array_filter(
            $definition->requires,
            fn (string $required): bool => $this->features->state($tenant, $required) !== ModuleState::Enabled,
        ));

        if ($missing !== []) {
            throw ApiException::unprocessable('module_requirements_not_enabled', 'Enable the required modules first.', [
                'module' => $key,
                'requires' => $missing,
            ]);
        }

        $lifecycle = $this->registry->lifecycle($key);

        // Default data first: a failed seed leaves the module unchanged.
        if ($lifecycle !== null && $row?->enabled_at === null) {
            $this->inTenant($tenant, static fn () => $lifecycle->seedDefaults());
        }

        $this->write($tenant, $key, TenantModule::ENABLED, $actor);

        if ($lifecycle !== null) {
            $this->inTenant($tenant, static fn () => $lifecycle->onEnabled());
        }

        $this->notify($tenant, $key, ModuleState::Enabled);
    }

    public function disable(Tenant $tenant, string $key, User $actor): void
    {
        $this->definition($key);
        $state = $this->features->state($tenant, $key);

        $this->assertChangeable($key, $state);

        $row = $this->row($tenant, $key);

        if ($row === null || $row->status === TenantModule::DISABLED) {
            return;
        }

        $dependents = array_values(array_filter(
            $this->registry->dependents($key),
            fn (string $dependent): bool => $this->features->state($tenant, $dependent) === ModuleState::Enabled,
        ));

        if ($dependents !== []) {
            throw ApiException::unprocessable('module_has_dependents', 'Disable the modules that require this one first.', [
                'module' => $key,
                'dependents' => $dependents,
            ]);
        }

        $lifecycle = $this->registry->lifecycle($key);

        if ($lifecycle !== null) {
            $blockers = $this->inTenant($tenant, static fn (): array => $lifecycle->disableBlockers());

            if ($blockers !== []) {
                throw ApiException::unprocessable('module_disable_blocked', 'The module cannot be disabled yet.', [
                    'module' => $key,
                    'reasons' => $blockers,
                ]);
            }
        }

        $this->write($tenant, $key, TenantModule::DISABLED, $actor);

        if ($lifecycle !== null) {
            $this->inTenant($tenant, static fn () => $lifecycle->onDisabled());
        }

        $this->notify($tenant, $key, ModuleState::Disabled);
    }

    /**
     * Inserts "enabled" rows for entitled auto modules that have none (spec
     * Â§11.5). Runs at provisioning, when a plan change applies and when a
     * grant override is created. Tenant hooks run only once the tenant
     * database has been migrated.
     *
     * @return list<string> the keys newly enabled
     */
    public function syncAutoModules(Tenant $tenant): array
    {
        $existing = TenantModule::query()->where('tenant_id', $tenant->getTenantKey())->pluck('module_key')->all();
        $enabled = [];

        foreach ($this->registry->all() as $key => $definition) {
            if (! $definition->isAuto() || in_array($key, $existing, true) || ! $this->features->isEntitled($tenant, $key)) {
                continue;
            }

            try {
                TenantModule::query()->create([
                    'tenant_id' => $tenant->getTenantKey(),
                    'module_key' => $key,
                    'status' => TenantModule::ENABLED,
                    'enabled_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent sync inserted it.
                continue;
            }

            $this->log($tenant, $key, TenantModule::ENABLED, null);
            $enabled[] = $key;
        }

        if ($enabled === []) {
            return [];
        }

        $this->features->flush($tenant);

        if ($tenant->getAttribute('schema_version') !== null) {
            foreach ($enabled as $key) {
                $lifecycle = $this->registry->lifecycle($key);

                if ($lifecycle !== null) {
                    $this->inTenant($tenant, static function () use ($lifecycle): void {
                        $lifecycle->seedDefaults();
                        $lifecycle->onEnabled();
                    });
                }

                $this->notify($tenant, $key, ModuleState::Enabled);
            }
        }

        return $enabled;
    }

    private function assertChangeable(string $key, ModuleState $state): void
    {
        if (in_array($state, [ModuleState::Available, ModuleState::Enabled, ModuleState::Disabled], true)) {
            return;
        }

        $details = ['module' => $key, 'state' => $state->value];

        if (in_array($state, [ModuleState::Locked, ModuleState::Unavailable], true)) {
            $details['entitled_by_plans'] = $this->features->entitledByPlans($key);
        }

        throw ApiException::forbidden($state->errorCode(), match ($state) {
            ModuleState::Suspended => 'This module is suspended by the platform.',
            ModuleState::Locked => 'Your plan no longer includes this module.',
            default => 'Your plan does not include this module.',
        }, $details);
    }

    private function write(Tenant $tenant, string $key, string $status, User $actor): void
    {
        DB::connection('landlord')->transaction(function () use ($tenant, $key, $status, $actor): void {
            /** @var TenantModule|null $row */
            $row = TenantModule::query()
                ->where('tenant_id', $tenant->getTenantKey())
                ->where('module_key', $key)
                ->lockForUpdate()
                ->first();

            $row ??= new TenantModule(['tenant_id' => $tenant->getTenantKey(), 'module_key' => $key]);

            $row->fill([
                'status' => $status,
                'changed_by_user_id' => $actor->getKey(),
                'changed_by_email' => $actor->email,
            ]);
            $row->setAttribute($status === TenantModule::ENABLED ? 'enabled_at' : 'disabled_at', now());
            $row->save();
        });

        $this->features->flush($tenant);
        $this->log($tenant, $key, $status, $actor);
    }

    private function log(Tenant $tenant, string $key, string $status, ?User $actor): void
    {
        ActivityRecorder::landlord('module_activation', "Module [{$key}] {$status}", $tenant, [
            'tenant_id' => $tenant->getTenantKey(),
            'module_key' => $key,
            'status' => $status,
            'user_id' => $actor?->getKey(),
            'user_email' => $actor?->email,
        ]);
    }

    private function notify(Tenant $tenant, string $key, ModuleState $state): void
    {
        $this->inTenant($tenant, fn () => $this->notifications->dispatch('module.state_changed', null, [
            'module_name' => $this->registry->get($key)->name,
            'state' => $state->value,
            'detail' => '',
        ], data: ['module' => $key, 'state' => $state->value]));
    }

    private function row(Tenant $tenant, string $key): ?TenantModule
    {
        return TenantModule::query()->where('tenant_id', $tenant->getTenantKey())->where('module_key', $key)->first();
    }

    private function definition(string $key): ModuleDefinition
    {
        if (! $this->registry->has($key)) {
            throw ApiException::unprocessable('module_unknown', "Unknown module [{$key}].", ['module' => $key]);
        }

        return $this->registry->get($key);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inTenant(Tenant $tenant, callable $callback): mixed
    {
        if (tenant()?->getTenantKey() === $tenant->getTenantKey()) {
            return $callback();
        }

        return $tenant->run(static fn () => $callback());
    }
}
