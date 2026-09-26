<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Models\UnitOfMeasure;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The small catalogue reference sets: units of measure (§29.1), tags
 * (§29.4) and product options with their values (§28.2). Each exposes the
 * operations of the spec's UnitOfMeasureService, TagService and
 * ProductOptionService.
 */
final readonly class CatalogReferenceService
{
    // ---- Units (§29.1) ------------------------------------------------

    /**
     * @return Collection<int, UnitOfMeasure>
     */
    public function listUnits(): Collection
    {
        return UnitOfMeasure::query()->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createUnit(array $data): UnitOfMeasure
    {
        /** @var UnitOfMeasure $unit */
        $unit = UnitOfMeasure::query()->create($this->validateUnit($data, null));

        return $unit;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateUnit(UnitOfMeasure $unit, array $data): UnitOfMeasure
    {
        $unit->fill($this->validateUnit($data, $unit))->save();

        return $unit;
    }

    public function deleteUnit(UnitOfMeasure $unit): void
    {
        if ($unit->short_code === UnitOfMeasure::DEFAULT_CODE) {
            throw ApiException::unprocessable('unit_default_protected', 'The default unit cannot be deleted.');
        }

        if (Product::withTrashed()->where('unit_id', $unit->id)->exists()) {
            throw ApiException::unprocessable('unit_in_use', 'Products use this unit.');
        }

        $unit->delete();
    }

    // ---- Tags (§29.4) -------------------------------------------------

    /**
     * @return Collection<int, Tag>
     */
    public function listTags(): Collection
    {
        return Tag::query()->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTag(array $data): Tag
    {
        $validated = validator($data, [
            'name' => ['required', 'string', 'max:64', Rule::unique('tenant.tags', 'name')],
            'slug' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tenant.tags', 'slug')],
        ])->validate();

        /** @var Tag $tag */
        $tag = Tag::query()->create($validated);

        return $tag;
    }

    public function deleteTag(Tag $tag): void
    {
        $tag->delete();
    }

    /**
     * @param  list<int>  $tagIds
     */
    public function syncProductTags(Product $product, array $tagIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $tagIds)));

        if (Tag::query()->whereKey($ids)->count() !== count($ids)) {
            throw ApiException::unprocessable('tag_unknown', 'Every tag must exist.');
        }

        $product->tags()->sync($ids);
    }

    // ---- Options (§28.2) ----------------------------------------------

    /**
     * @return Collection<int, ProductOption>
     */
    public function listOptions(): Collection
    {
        return ProductOption::query()->with('values')->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOption(array $data): ProductOption
    {
        $validated = validator($data, [
            'name' => ['required', 'string', 'max:64', Rule::unique('tenant.product_options', 'name')],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'values' => ['sometimes', 'array', 'max:200'],
            'values.*' => ['string', 'max:64', 'distinct'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($validated): ProductOption {
            /** @var ProductOption $option */
            $option = ProductOption::query()->create(['name' => $validated['name'], 'sort_order' => $validated['sort_order'] ?? 0]);

            foreach (array_values($validated['values'] ?? []) as $i => $value) {
                $option->values()->create(['value' => $value, 'sort_order' => $i]);
            }

            return $option->load('values');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOption(ProductOption $option, array $data): ProductOption
    {
        $option->fill(validator($data, [
            'name' => ['sometimes', 'string', 'max:64', Rule::unique('tenant.product_options', 'name')->ignore($option->id)],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ])->validate())->save();

        return $option->load('values');
    }

    public function deleteOption(ProductOption $option): void
    {
        if ($this->valuesInUse($option->values()->pluck('id')->all())) {
            throw ApiException::unprocessable('option_in_use', 'Variants use values of this option.');
        }

        $option->delete();
    }

    public function addValue(ProductOption $option, string $value): ProductOptionValue
    {
        validator(['value' => $value], [
            'value' => ['required', 'string', 'max:64', Rule::unique('tenant.product_option_values', 'value')->where('product_option_id', $option->id)],
        ])->validate();

        /** @var ProductOptionValue $created */
        $created = $option->values()->create(['value' => trim($value), 'sort_order' => (int) $option->values()->max('sort_order') + 1]);

        return $created;
    }

    public function removeValue(ProductOptionValue $value): void
    {
        if ($this->valuesInUse([$value->id])) {
            throw ApiException::unprocessable('option_value_in_use', 'Variants use this value.');
        }

        $value->delete();
    }

    /**
     * @param  list<int>  $valueIds
     */
    private function valuesInUse(array $valueIds): bool
    {
        return $valueIds !== [] && DB::connection('tenant')->table('product_variant_option_values')
            ->join('product_variants', 'product_variants.id', '=', 'product_variant_option_values.product_variant_id')
            ->whereIn('product_option_value_id', $valueIds)
            ->whereNull('product_variants.deleted_at')
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateUnit(array $data, ?UnitOfMeasure $existing): array
    {
        $req = $existing === null ? 'required' : 'sometimes';

        return validator($data, [
            'name' => [$req, 'string', 'max:64', Rule::unique('tenant.units_of_measure', 'name')->ignore($existing?->id)],
            'short_code' => [$req, 'string', 'max:16', Rule::unique('tenant.units_of_measure', 'short_code')->ignore($existing?->id)],
            'allows_decimal' => ['sometimes', 'boolean'],
        ])->validate();
    }
}
