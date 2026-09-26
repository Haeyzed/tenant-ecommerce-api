<?php

declare(strict_types=1);

namespace App\Modules\CustomFields\Services;

use App\Modules\CustomFields\Models\CustomFieldDefinition;
use App\Modules\CustomFields\Models\CustomFieldValue;
use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\CustomFields\Support\CustomFieldTypes;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Custom field definitions (spec §23.2, §23.6). max_custom_fields is
 * enforced on the create and reactivate routes by usage.limit, under the
 * per-tenant limit lock; the owning module's state by the registry.
 */
final readonly class CustomFieldDefinitionService
{
    private const array IMMUTABLE = ['key', 'field_type', 'entity_type'];

    public function __construct(
        private CustomFieldEntityRegistry $entities,
        private TenantSettingsService $settings,
    ) {}

    /**
     * @return Collection<int, CustomFieldDefinition>
     */
    public function list(string $entityType, ?bool $isActive = null): Collection
    {
        $this->entities->get($entityType);

        return CustomFieldDefinition::query()
            ->where('entity_type', $entityType)
            ->when($isActive !== null, static fn ($q) => $q->where('is_active', $isActive))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CustomFieldDefinition
    {
        $entityType = (string) ($data['entity_type'] ?? '');
        $this->entities->assertDefinitionsWritable($this->tenant(), $entityType);

        $validated = $this->validateDefinition($data, null);

        return DB::connection('tenant')->transaction(static function () use ($validated, $entityType): CustomFieldDefinition {
            $validated['sort_order'] ??= (int) CustomFieldDefinition::query()->where('entity_type', $entityType)->max('sort_order') + 1;

            /** @var CustomFieldDefinition $definition */
            $definition = CustomFieldDefinition::query()->create($validated);

            return $definition;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomFieldDefinition $definition, array $data): CustomFieldDefinition
    {
        $this->entities->assertDefinitionsWritable($this->tenant(), $definition->entity_type);

        foreach (self::IMMUTABLE as $attribute) {
            if (array_key_exists($attribute, $data) && $data[$attribute] !== $definition->{$attribute}) {
                throw ValidationException::withMessages([$attribute => ["The {$attribute} of a custom field cannot change. Create a new field instead."]]);
            }
        }

        $validated = $this->validateDefinition(array_merge($definition->only(['entity_type', 'key', 'field_type']), $data), $definition);

        if (CustomFieldTypes::hasOptions($definition->field_type) && array_key_exists('options', $validated)) {
            $this->assertRemovedOptionsUnused($definition, array_map(static fn (array $o): string => (string) $o['value'], (array) $validated['options']));
        }

        $definition->fill($validated)->save();

        return $definition;
    }

    public function deactivate(CustomFieldDefinition $definition): CustomFieldDefinition
    {
        $this->entities->assertDefinitionsWritable($this->tenant(), $definition->entity_type);
        $definition->forceFill(['is_active' => false])->save();

        return $definition;
    }

    public function reactivate(CustomFieldDefinition $definition): CustomFieldDefinition
    {
        $this->entities->assertDefinitionsWritable($this->tenant(), $definition->entity_type);
        $definition->forceFill(['is_active' => true])->save();

        return $definition;
    }

    /**
     * Only a definition without values can be deleted; otherwise it is
     * deactivated so the data is kept.
     */
    public function delete(CustomFieldDefinition $definition): void
    {
        $this->entities->assertDefinitionsWritable($this->tenant(), $definition->entity_type);

        if ($this->valueCount($definition) > 0) {
            throw ApiException::unprocessable('custom_field_has_values', 'This field has values. Deactivate it instead.', ['values' => $this->valueCount($definition)]);
        }

        $definition->delete();
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorder(string $entityType, array $orderedIds): void
    {
        $this->entities->assertDefinitionsWritable($this->tenant(), $entityType);

        $known = CustomFieldDefinition::query()->where('entity_type', $entityType)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $given = array_values(array_unique(array_map('intval', $orderedIds)));

        if (array_diff($given, $known) !== [] || count($given) !== count($known)) {
            throw ValidationException::withMessages(['ordered_ids' => ['List every field of this record type exactly once.']]);
        }

        DB::connection('tenant')->transaction(static function () use ($given): void {
            foreach ($given as $position => $id) {
                CustomFieldDefinition::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });
    }

    public function valueCount(CustomFieldDefinition $definition): int
    {
        return CustomFieldValue::query()->where('custom_field_definition_id', $definition->id)->count();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateDefinition(array $data, ?CustomFieldDefinition $existing): array
    {
        $type = (string) ($data['field_type'] ?? '');
        $creating = $existing === null;
        $req = $creating ? 'required' : 'sometimes';

        $rules = [
            'entity_type' => [$req, 'string'],
            'key' => [$req, 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/', Rule::unique('tenant.custom_field_definitions', 'key')
                ->where('entity_type', (string) ($data['entity_type'] ?? ''))->ignore($existing?->id)],
            'label' => [$req, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'placeholder' => ['sometimes', 'nullable', 'string', 'max:255'],
            'field_type' => [$req, Rule::in(CustomFieldTypes::TYPES)],
            'options' => CustomFieldTypes::hasOptions($type) ? [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:'.CustomFieldTypes::MAX_OPTIONS] : ['prohibited'],
            'options.*.value' => ['required', 'string', 'max:100', 'distinct'],
            'options.*.label' => ['required', 'string', 'max:120'],
            'display' => ['sometimes', 'nullable', Rule::in(CustomFieldTypes::displays($type))],
            'default_value' => ['sometimes', 'nullable'],
            'is_required' => ['sometimes', 'boolean'],
            'is_admin_only' => ['sometimes', 'boolean'],
            'validation' => ['sometimes', 'nullable', 'array'],
            'show_in_table' => ['sometimes', 'boolean'],
            'show_on_form' => ['sometimes', 'boolean'],
            'show_on_detail' => ['sometimes', 'boolean'],
            'show_on_documents' => ['sometimes', 'boolean'],
            'is_searchable' => ['sometimes', 'boolean', in_array($type, CustomFieldTypes::TEXT_TYPES, true) ? 'nullable' : 'declined'],
            'is_filterable' => ['sometimes', 'boolean', in_array($type, [...CustomFieldTypes::FILE_TYPES, 'textarea'], true) ? 'declined' : 'nullable'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            ...CustomFieldTypes::validationRules($type),
        ];

        $validator = Validator::make($data, $rules, [
            'is_searchable.declined' => 'Only text fields can be searchable.',
            'is_filterable.declined' => 'This field type cannot be filtered.',
        ]);

        $validator->after(function ($validator) use ($data, $type): void {
            $pattern = $data['validation']['pattern'] ?? null;

            if ($type === 'text' && is_string($pattern) && $pattern !== '' && ! CustomFieldTypes::isSafePattern($pattern)) {
                $validator->errors()->add('validation.pattern', 'This pattern is not a safe regular expression.');
            }

            if (array_key_exists('default_value', $data) && $data['default_value'] !== null) {
                if (in_array($type, CustomFieldTypes::FILE_TYPES, true)) {
                    $validator->errors()->add('default_value', 'File fields have no default value.');

                    return;
                }

                $probe = new CustomFieldDefinition([
                    'field_type' => $type,
                    'options' => $data['options'] ?? null,
                    'validation' => $data['validation'] ?? null,
                    'is_required' => false,
                ]);
                $check = Validator::make(['v' => $data['default_value']], ['v' => CustomFieldTypes::valueRules($probe, $this->timezone())]);

                foreach ($check->errors()->get('v') as $message) {
                    $validator->errors()->add('default_value', str_replace('v ', 'default value ', $message));
                }
            }
        });

        $validated = $validator->validate();

        // Only the keys the type understands are stored in `validation`.
        if (array_key_exists('validation', $validated)) {
            $allowed = array_map(static fn (string $k): string => substr($k, 11), array_filter(
                array_keys(CustomFieldTypes::validationRules($type)),
                static fn (string $k): bool => substr_count($k, '.') === 1,
            ));
            $validated['validation'] = array_intersect_key((array) $validated['validation'], array_flip($allowed)) ?: null;
        }

        return $validated;
    }

    /**
     * @param  list<string>  $newValues
     */
    private function assertRemovedOptionsUnused(CustomFieldDefinition $definition, array $newValues): void
    {
        $removed = array_values(array_diff($definition->optionValues(), $newValues));

        if ($removed === []) {
            return;
        }

        $inUse = $definition->field_type === 'select'
            ? CustomFieldValue::query()->where('custom_field_definition_id', $definition->id)->whereIn('value_string', $removed)->exists()
            : CustomFieldValue::query()->where('custom_field_definition_id', $definition->id)
                ->where(static function ($q) use ($removed): void {
                    foreach ($removed as $value) {
                        $q->orWhereJsonContains('value_json', $value);
                    }
                })->exists();

        if ($inUse) {
            throw ApiException::unprocessable('custom_field_option_in_use', 'An option you removed is still used by records.', ['options' => $removed]);
        }
    }

    private function timezone(): string
    {
        return (string) ($this->settings->get('timezone') ?: 'UTC');
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return tenant();
    }
}
