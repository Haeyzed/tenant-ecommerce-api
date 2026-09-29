<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Restaurant\Models\ModifierGroup;
use App\Modules\Restaurant\Models\ModifierOption;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Modifier groups and their options (spec §65.2), reused across products.
 * Lines already sold keep their snapshots when an option changes or goes.
 */
final readonly class ModifierGroupService
{
    /**
     * @param  array<string, mixed>  $data  name, selection_type, is_required?, options[]? (name, price_adjustment?, is_active?)
     */
    public function createGroup(array $data): ModifierGroup
    {
        $options = [];

        foreach ($this->optionRules(true) as $field => $rules) {
            $options["options.*.{$field}"] = $rules;
        }

        $validated = Validator::make($data, [...$this->groupRules(true), 'options' => ['sometimes', 'array', 'max:50'], ...$options])->validate();

        return DB::connection('tenant')->transaction(function () use ($validated): ModifierGroup {
            $group = new ModifierGroup;
            $group->forceFill(array_intersect_key($validated, array_flip(['name', 'selection_type', 'is_required'])))->save();

            foreach ($validated['options'] ?? [] as $option) {
                $this->saveOption(new ModifierOption, $group, $option);
            }

            return $group->load('options');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateGroup(ModifierGroup $group, array $data): ModifierGroup
    {
        $group->forceFill(Validator::make($data, $this->groupRules(false))->validate())->save();

        return $group->load('options');
    }

    public function deleteGroup(ModifierGroup $group): void
    {
        // Options and product links go with it; sold lines keep their snapshots.
        $group->delete();
    }

    /**
     * @param  array<string, mixed>  $data  name, price_adjustment?, is_active?
     */
    public function addOption(ModifierGroup $group, array $data): ModifierOption
    {
        return $this->saveOption(new ModifierOption, $group, Validator::make($data, $this->optionRules(true))->validate());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOption(ModifierOption $option, array $data): ModifierOption
    {
        return $this->saveOption($option, $option->group, Validator::make($data, $this->optionRules(false))->validate());
    }

    public function deleteOption(ModifierOption $option): void
    {
        $option->delete();
    }

    public function attachToProduct(Product $product, ModifierGroup $group): void
    {
        DB::connection('tenant')->table('product_modifier_group')->insertOrIgnore(['product_id' => $product->id, 'modifier_group_id' => $group->id]);
    }

    public function detachFromProduct(Product $product, ModifierGroup $group): void
    {
        DB::connection('tenant')->table('product_modifier_group')->where('product_id', $product->id)->where('modifier_group_id', $group->id)->delete();
    }

    /**
     * @param  array{product_id?: int}  $filters
     * @return Collection<int, ModifierGroup>
     */
    public function listGroups(array $filters = []): Collection
    {
        return ModifierGroup::query()->with('options')
            ->when(isset($filters['product_id']), static fn ($q) => $q->whereIn('id', static fn ($s) => $s->select('modifier_group_id')->from('product_modifier_group')->where('product_id', $filters['product_id'])))
            ->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveOption(ModifierOption $option, ModifierGroup $group, array $data): ModifierOption
    {
        $option->forceFill([...$data, 'modifier_group_id' => $group->id])->save();

        return $option;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function groupRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:80'],
            'selection_type' => [$required, Rule::in(ModifierGroup::SELECTION_TYPES)],
            'is_required' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function optionRules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            // A reduction ("half portion") may be negative.
            'price_adjustment' => ['sometimes', 'numeric', 'decimal:0,4', 'min:-1000000', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
