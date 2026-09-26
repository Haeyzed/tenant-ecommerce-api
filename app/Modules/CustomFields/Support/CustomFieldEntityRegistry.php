<?php

declare(strict_types=1);

namespace App\Modules\CustomFields\Support;

use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The entities that accept custom fields (spec §23.1). Each code module
 * registers its entity when it introduces the entity's table: core entities
 * with no owner, module entities with the owning feature key (which must
 * list the type in its custom_field_entities registry entry). Entity types
 * are stable strings, never class names.
 */
final class CustomFieldEntityRegistry
{
    /** @var array<string, EntityDefinition> */
    private array $entities = [];

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly FeatureAccessService $features,
    ) {}

    /**
     * @param  class-string<Model>  $model
     * @param  string  $permissionResource  the entity's §12.4 resource, e.g. "products"
     */
    public function register(string $type, string $model, string $permissionResource, ?string $ownerModule = null): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,47}$/', $type) !== 1) {
            throw new InvalidArgumentException("Invalid custom-field entity type [{$type}].");
        }

        if ($ownerModule !== null && ! in_array($type, $this->modules->get($ownerModule)->customFieldEntities, true)) {
            throw new InvalidArgumentException("Module [{$ownerModule}] does not declare custom-field entity [{$type}].");
        }

        $this->entities[$type] = new EntityDefinition($type, $model, $permissionResource, $ownerModule);
    }

    public function has(string $type): bool
    {
        return isset($this->entities[$type]);
    }

    public function get(string $type): EntityDefinition
    {
        return $this->entities[$type] ?? throw ApiException::unprocessable('custom_field_entity_unknown', 'This record type does not accept custom fields.', ['entity_type' => $type]);
    }

    public function ownerModule(string $type): ?string
    {
        return $this->get($type)->ownerModule;
    }

    /**
     * The owning module's state for the tenant; null for core entities,
     * which are always active.
     */
    public function state(Tenant $tenant, string $type): ?ModuleState
    {
        $owner = $this->get($type)->ownerModule;

        return $owner === null ? null : $this->features->state($tenant, $owner);
    }

    /**
     * Entities offered to the tenant: core ones, and those of modules in a
     * state other than unavailable or available (§23.6).
     *
     * @return list<array{entity_type: string, owner_module: string|null, state: string}>
     */
    public function all(Tenant $tenant): array
    {
        $offered = [];

        foreach ($this->entities as $type => $entity) {
            $state = $this->state($tenant, $type);

            if ($state === ModuleState::Unavailable || $state === ModuleState::Available) {
                continue;
            }

            $offered[] = ['entity_type' => $type, 'owner_module' => $entity->ownerModule, 'state' => $state?->value ?? 'core'];
        }

        return $offered;
    }

    /**
     * Definitions may be created or changed only while the owner is
     * enabled; an entity not offered to the tenant is unknown to it.
     */
    public function assertDefinitionsWritable(Tenant $tenant, string $type): void
    {
        $state = $this->state($tenant, $type);

        if ($state === null || $state === ModuleState::Enabled) {
            return;
        }

        if ($state === ModuleState::Unavailable || $state === ModuleState::Available) {
            throw ApiException::unprocessable('custom_field_entity_unknown', 'This record type does not accept custom fields.', ['entity_type' => $type]);
        }

        throw $this->moduleForbidden((string) $this->get($type)->ownerModule, $state);
    }

    /**
     * Values may be written only while the owner is enabled (§23.4).
     */
    public function assertValuesWritable(Tenant $tenant, string $type): void
    {
        $state = $this->state($tenant, $type);

        if ($state !== null && $state !== ModuleState::Enabled) {
            throw $this->moduleForbidden((string) $this->get($type)->ownerModule, $state);
        }
    }

    /**
     * Values are returned unless the owner is suspended (or not offered).
     */
    public function valuesVisible(Tenant $tenant, string $type): bool
    {
        $state = $this->state($tenant, $type);

        return $state === null || in_array($state, [ModuleState::Enabled, ModuleState::Disabled, ModuleState::Locked], true);
    }

    private function moduleForbidden(string $module, ModuleState $state): ApiException
    {
        return ApiException::forbidden($state->errorCode(), match ($state) {
            ModuleState::Suspended => 'This module is suspended by the platform.',
            ModuleState::Locked => 'Your plan no longer includes this module. Its data is read-only.',
            default => 'This module is not enabled.',
        }, ['module' => $module, 'state' => $state->value]);
    }
}
