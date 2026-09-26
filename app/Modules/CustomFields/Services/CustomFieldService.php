<?php

declare(strict_types=1);

namespace App\Modules\CustomFields\Services;

use App\Modules\CustomFields\Models\CustomFieldDefinition;
use App\Modules\CustomFields\Models\CustomFieldValue;
use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\CustomFields\Support\CustomFieldTypes;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Media\StorageQuota;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Custom field values of registered entities (spec §23.5). Owning modules
 * call validate() from their form requests (errors under custom_fields.*),
 * save() in their write transaction, and valuesFor()/eagerLoad() from their
 * resources. No module contains custom-field code of its own.
 */
final class CustomFieldService
{
    public const string ADMIN = 'admin';

    public const string PUBLIC = 'public';

    public const string MEDIA_COLLECTION = 'custom_fields';

    /** Private disk: custom-field files are never publicly addressable. */
    public const string DISK = 'local';

    private const int URL_MINUTES = 30;

    /** @var array<string, array<int, array<string, mixed>>> entity type => entity id => values */
    private array $loaded = [];

    public function __construct(
        private readonly CustomFieldEntityRegistry $entities,
        private readonly TenantSettingsService $settings,
        private readonly StorageQuota $quota,
    ) {}

    /**
     * Validated, normalised values keyed by field key; null clears a value.
     * Unknown, inactive or (public context) admin-only keys are rejected.
     * On create, required fields must be present and defaults apply.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     *
     * @throws ValidationException errors under custom_fields.{key}
     */
    public function validate(string $entityType, array $values, string $context, bool $creating = false): array
    {
        $this->entities->assertValuesWritable($this->tenant(), $entityType);

        $definitions = $this->definitions($entityType, $context);
        $timezone = $this->timezone();
        $errors = [];
        $clean = [];

        foreach (array_keys($values) as $key) {
            if (! isset($definitions[$key])) {
                $errors['custom_fields.'.$key][] = 'This field does not exist.';
            }
        }

        foreach ($definitions as $key => $definition) {
            if (in_array($definition->field_type, CustomFieldTypes::FILE_TYPES, true)) {
                if (array_key_exists($key, $values)) {
                    $errors['custom_fields.'.$key][] = 'Upload files through the record\'s media endpoint.';
                }

                continue;
            }

            if (! array_key_exists($key, $values)) {
                if ($creating && $definition->default_value !== null) {
                    $values[$key] = $definition->default_value;
                } elseif ($creating && $definition->is_required) {
                    $errors['custom_fields.'.$key][] = "The {$definition->label} field is required.";

                    continue;
                } else {
                    continue;
                }
            }

            $value = $values[$key];

            if ($value === null || $value === '' || $value === []) {
                if ($definition->is_required) {
                    $errors['custom_fields.'.$key][] = "The {$definition->label} field is required.";
                } else {
                    $clean[$key] = null;
                }

                continue;
            }

            $validator = Validator::make(['value' => $value], ['value' => CustomFieldTypes::valueRules($definition, $timezone)], [], ['value' => $definition->label]);

            if ($definition->field_type === 'multi_select') {
                $validator->addRules(['value.*' => ['string', 'in:'.implode(',', $definition->optionValues())]]);
            }

            if ($validator->fails()) {
                $errors['custom_fields.'.$key] = array_values($validator->errors()->all());

                continue;
            }

            $clean[$key] = CustomFieldTypes::normalize($definition, $value, $timezone);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * Writes validated values; call inside the entity's own transaction.
     * An empty value deletes the row. Changes are recorded against the
     * entity with the field key as the attribute.
     *
     * @param  array<string, mixed>  $values  output of validate()
     */
    public function save(Model $entity, string $entityType, array $values): void
    {
        if ($values === []) {
            return;
        }

        $definitions = CustomFieldDefinition::query()->where('entity_type', $entityType)->whereIn('key', array_keys($values))->get()->keyBy('key');
        $changes = [];

        DB::connection('tenant')->transaction(static function () use ($entity, $entityType, $values, $definitions, &$changes): void {
            foreach ($values as $key => $value) {
                /** @var CustomFieldDefinition|null $definition */
                $definition = $definitions->get($key);
                $column = $definition === null ? null : CustomFieldTypes::column($definition->field_type);

                if ($column === null) {
                    continue;
                }

                $existing = CustomFieldValue::query()
                    ->where('custom_field_definition_id', $definition->id)
                    ->where('entity_id', $entity->getKey())
                    ->lockForUpdate()
                    ->first();
                $old = $existing?->getRawOriginal($column);

                if ($value === null) {
                    $existing?->delete();
                } else {
                    CustomFieldValue::query()->updateOrCreate(
                        ['custom_field_definition_id' => $definition->id, 'entity_id' => $entity->getKey()],
                        ['entity_type' => $entityType, $column => $value],
                    );
                }

                $new = is_array($value) ? json_encode($value) : $value;

                if ((string) $old !== (string) $new) {
                    $changes[$key] = ['old' => $old, 'new' => $new];
                }
            }
        });

        unset($this->loaded[$entityType][$entity->getKey()]);

        if ($changes !== []) {
            ActivityRecorder::tenant('custom_fields', 'Custom fields updated', $entity, ['custom_fields' => $changes]);
        }
    }

    /**
     * Stores an upload for a file or image field on the entity's
     * custom_fields media collection; replaces any earlier file.
     */
    public function attachFile(Model&HasMedia $entity, string $entityType, string $key, UploadedFile $file): Media
    {
        $this->entities->assertValuesWritable($this->tenant(), $entityType);

        /** @var CustomFieldDefinition|null $definition */
        $definition = CustomFieldDefinition::query()->where('entity_type', $entityType)->where('key', $key)->where('is_active', true)->first();

        if ($definition === null || ! in_array($definition->field_type, CustomFieldTypes::FILE_TYPES, true)) {
            throw ValidationException::withMessages(['field_key' => ['This is not a file field.']]);
        }

        Validator::make(['file' => $file], ['file' => CustomFieldTypes::uploadRules($definition)])->validate();
        $this->quota->assertAllows($file);

        return DB::connection('tenant')->transaction(function () use ($entity, $entityType, $definition, $file): Media {
            $entity->getMedia(self::MEDIA_COLLECTION, ['field_key' => $definition->key])->each->delete();

            $media = $entity->addMedia($file)
                ->usingFileName(Str::uuid().'.'.$file->guessExtension())
                ->usingName(mb_substr($file->getClientOriginalName(), 0, 200))
                ->withCustomProperties(['field_key' => $definition->key])
                ->toMediaCollection(self::MEDIA_COLLECTION, self::DISK);

            CustomFieldValue::query()->updateOrCreate(
                ['custom_field_definition_id' => $definition->id, 'entity_id' => $entity->getKey()],
                ['entity_type' => $entityType],
            );

            unset($this->loaded[$entityType][$entity->getKey()]);

            return $media;
        });
    }

    /**
     * The entity's values for the caller's context, keyed by field key;
     * active fields only, every field present (null when unset).
     *
     * @return array<string, mixed>
     */
    public function valuesFor(Model $entity, string $entityType, string $context): array
    {
        if (! $this->entities->valuesVisible($this->tenant(), $entityType)) {
            return [];
        }

        $id = (int) $entity->getKey();

        if (! isset($this->loaded[$entityType][$id][$context])) {
            $this->eagerLoad(new EloquentCollection([$entity]), $entityType, $context);
        }

        return $this->loaded[$entityType][$id][$context] ?? [];
    }

    /**
     * Loads the values of many entities in one query (lists).
     *
     * @param  Collection<int, Model>  $entities
     */
    public function eagerLoad(Collection $entities, string $entityType, string $context): void
    {
        $ids = $entities->map(static fn (Model $m): int => (int) $m->getKey())->all();

        if ($ids === []) {
            return;
        }

        $definitions = $this->definitions($entityType, $context);
        $timezone = $this->timezone();
        $rows = CustomFieldValue::query()
            ->where('entity_type', $entityType)
            ->whereIn('entity_id', $ids)
            ->whereIn('custom_field_definition_id', array_map(static fn (CustomFieldDefinition $d): int => $d->id, $definitions))
            ->get()
            ->groupBy('entity_id');

        foreach ($entities as $entity) {
            $id = (int) $entity->getKey();
            $values = array_fill_keys(array_keys($definitions), null);

            foreach ($rows->get($id, collect()) as $row) {
                $definition = collect($definitions)->first(static fn (CustomFieldDefinition $d): bool => $d->id === $row->custom_field_definition_id);

                if ($definition === null) {
                    continue;
                }

                $values[$definition->key] = in_array($definition->field_type, CustomFieldTypes::FILE_TYPES, true)
                    ? $this->fileValue($entity, $definition)
                    : CustomFieldTypes::present($definition, $this->raw($definition, $row), $timezone);
            }

            $this->loaded[$entityType][$id][$context] = $values;
        }
    }

    /**
     * cf[{key}] filters for filterable fields (§23.5).
     *
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    public function applyFilters(Builder $query, string $entityType, array $filters): Builder
    {
        $definitions = CustomFieldDefinition::query()->where('entity_type', $entityType)->where('is_active', true)->where('is_filterable', true)->get()->keyBy('key');
        $keyColumn = $query->getModel()->getQualifiedKeyName();
        $timezone = $this->timezone();

        foreach ($filters as $key => $filter) {
            /** @var CustomFieldDefinition|null $definition */
            $definition = $definitions->get($key);
            $column = $definition === null ? null : CustomFieldTypes::column($definition->field_type);

            if ($column === null) {
                throw ValidationException::withMessages(['cf.'.$key => ['This field cannot be filtered.']]);
            }

            $query->whereIn($keyColumn, static function ($sub) use ($definition, $column, $filter, $timezone): void {
                $sub->select('entity_id')->from('custom_field_values')->where('custom_field_definition_id', $definition->id);

                match ($definition->field_type) {
                    'multi_select' => $sub->where(static function ($q) use ($filter): void {
                        foreach ((array) $filter as $value) {
                            $q->orWhereJsonContains('value_json', (string) $value);
                        }
                    }),
                    'boolean' => $sub->where($column, filter_var($filter, FILTER_VALIDATE_BOOL) ? 1 : 0),
                    'number', 'decimal', 'currency', 'date', 'datetime' => is_array($filter)
                        ? $sub->when(isset($filter['from']), static fn ($q) => $q->where($column, '>=', self::bound($definition->field_type, (string) $filter['from'], $timezone, false)))
                            ->when(isset($filter['to']), static fn ($q) => $q->where($column, '<=', self::bound($definition->field_type, (string) $filter['to'], $timezone, true)))
                        : $sub->where($column, self::bound($definition->field_type, (string) $filter, $timezone, false)),
                    default => $sub->where($column, (string) $filter),
                };
            });
        }

        return $query;
    }

    /**
     * Prefix search over searchable text fields, OR-ed into the caller's
     * own search group.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function applySearch(Builder $query, string $entityType, string $term): Builder
    {
        $ids = CustomFieldDefinition::query()->where('entity_type', $entityType)->where('is_active', true)->where('is_searchable', true)->pluck('id');

        if ($ids->isEmpty() || trim($term) === '') {
            return $query;
        }

        $like = addcslashes(trim($term), '%_\\').'%';

        return $query->orWhereIn($query->getModel()->getQualifiedKeyName(), static fn ($sub) => $sub->select('entity_id')->from('custom_field_values')
            ->whereIn('custom_field_definition_id', $ids)
            ->where(static fn ($q) => $q->where('value_string', 'like', $like)->orWhere('value_text', 'like', $like)));
    }

    /**
     * Deletes an entity's values and files (on force delete or purge).
     */
    public function forget(Model $entity, string $entityType): void
    {
        CustomFieldValue::query()->where('entity_type', $entityType)->where('entity_id', $entity->getKey())->delete();

        if ($entity instanceof HasMedia) {
            $entity->clearMediaCollection(self::MEDIA_COLLECTION);
        }
    }

    /**
     * Active definitions visible in the context, keyed by field key.
     *
     * @return array<string, CustomFieldDefinition>
     */
    public function definitions(string $entityType, string $context): array
    {
        return CustomFieldDefinition::query()
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->when($context === self::PUBLIC, static fn ($q) => $q->where('is_admin_only', false))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('key')
            ->all();
    }

    private function raw(CustomFieldDefinition $definition, CustomFieldValue $row): mixed
    {
        $column = CustomFieldTypes::column($definition->field_type);

        return $column === 'value_json' ? $row->value_json : ($column === null ? null : $row->getRawOriginal($column));
    }

    /**
     * @return array{name: string, url: string, size: int}|null
     */
    private function fileValue(Model $entity, CustomFieldDefinition $definition): ?array
    {
        if (! $entity instanceof HasMedia) {
            return null;
        }

        $media = $entity->getMedia(self::MEDIA_COLLECTION, ['field_key' => $definition->key])->first();

        if ($media === null) {
            return null;
        }

        // Stored privately; served only through a short-lived signed URL.
        return [
            'name' => $media->name,
            'url' => URL::temporarySignedRoute('tenant.custom-fields.media', now()->addMinutes(self::URL_MINUTES), ['media' => $media->id]),
            'size' => (int) $media->size,
        ];
    }

    private static function bound(string $type, string $value, string $timezone, bool $upper): string
    {
        return match ($type) {
            'datetime' => CarbonImmutable::parse($value, $timezone)->utc()->format('Y-m-d H:i:s'),
            'date' => substr($value, 0, 10),
            default => is_numeric($value) ? $value : ($upper ? '999999999999' : '-999999999999'),
        };
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
