<?php

declare(strict_types=1);

namespace App\Modules\Restaurant\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Restaurant\Models\ModifierGroup;
use App\Modules\Restaurant\Models\ModifierOption;
use App\Modules\Restaurant\Models\OrderItemModifier;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;

/**
 * Modifier selections on a line (spec §65.2): checked against the groups
 * attached to the product (a single group takes at most one option, a
 * required group at least one), priced in the base currency and
 * snapshotted on the order line.
 */
final readonly class ModifierSelectionService
{
    /**
     * @param  list<int>  $optionIds
     * @return array{adjustment: string, modifiers: list<array{modifier_option_id: int, name: string, price_adjustment: string}>}
     */
    public function resolve(Product $product, array $optionIds, int $line = 0): array
    {
        $optionIds = array_map('intval', $optionIds);

        if (count($optionIds) !== count(array_unique($optionIds))) {
            throw ApiException::unprocessable('modifier_invalid', 'An option is chosen twice.', ['line' => $line]);
        }

        $groups = ModifierGroup::query()->whereIn('id', static fn ($q) => $q->select('modifier_group_id')->from('product_modifier_group')->where('product_id', $product->id))
            ->with(['options' => static fn ($q) => $q->where('is_active', true)])->get();

        /** @var array<int, ModifierOption> $options */
        $options = $groups->flatMap(static fn (ModifierGroup $g) => $g->options)->keyBy('id')->all();
        $chosen = [];

        foreach ($optionIds as $id) {
            $chosen[] = $options[$id] ?? throw ApiException::unprocessable('modifier_invalid', "{$product->name} does not offer this option.", ['line' => $line, 'modifier_option_id' => $id]);
        }

        foreach ($groups as $group) {
            $picked = count(array_filter($chosen, static fn (ModifierOption $o): bool => $o->modifier_group_id === $group->id));

            if ($group->selection_type === 'single' && $picked > 1) {
                throw ApiException::unprocessable('modifier_invalid', "Choose one option for {$group->name}.", ['line' => $line, 'modifier_group_id' => $group->id]);
            }

            if ($group->is_required && $picked === 0) {
                throw ApiException::unprocessable('modifier_required', "Choose an option for {$group->name}.", ['line' => $line, 'modifier_group_id' => $group->id]);
            }
        }

        $adjustment = Money::normalize(0);
        $modifiers = [];

        foreach ($chosen as $option) {
            $adjustment = Money::add($adjustment, (string) $option->price_adjustment);
            $modifiers[] = [
                'modifier_option_id' => $option->id,
                'name' => mb_substr($groups->firstWhere('id', $option->modifier_group_id)?->name.': '.$option->name, 0, 160),
                'price_adjustment' => Money::normalize((string) $option->price_adjustment),
            ];
        }

        return ['adjustment' => $adjustment, 'modifiers' => $modifiers];
    }

    /**
     * Snapshots each line's selections (§5.8). Orders sold in the base
     * currency only, so the base adjustment is the order-currency one.
     *
     * @param  list<OrderItem>  $items  in the order of $selections
     * @param  list<list<array{modifier_option_id: int, name: string, price_adjustment: string}>>  $selections
     */
    public function snapshot(array $items, array $selections): void
    {
        foreach ($items as $i => $item) {
            foreach ($selections[$i] ?? [] as $modifier) {
                $row = new OrderItemModifier;
                $row->forceFill([
                    'order_item_id' => $item->id,
                    'modifier_option_id' => $modifier['modifier_option_id'],
                    'name_snapshot' => $modifier['name'],
                    'price_adjustment_snapshot' => $modifier['price_adjustment'],
                ])->save();
            }
        }
    }
}
