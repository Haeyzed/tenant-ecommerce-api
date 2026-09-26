<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingZone;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shipping methods (spec §36.1, §36.3): a flat cost per order in the base
 * currency. Deleting is a soft delete; orders keep their reference.
 */
final readonly class ShippingMethodService
{
    /**
     * @param  array{shipping_zone_id?: int, fulfillment_type?: string, is_active?: bool}  $filters
     * @return Collection<int, ShippingMethod>
     */
    public function listMethods(array $filters = []): Collection
    {
        return ShippingMethod::query()->with('zone:id,name')
            ->when($filters['shipping_zone_id'] ?? null, static fn ($q, $v) => $q->where('shipping_zone_id', $v))
            ->when($filters['fulfillment_type'] ?? null, static fn ($q, $v) => $q->where('fulfillment_type', $v))
            ->when(array_key_exists('is_active', $filters), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('shipping_zone_id')->orderBy('cost')->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, ShippingMethod>
     */
    public function listMethodsForZone(ShippingZone $zone): Collection
    {
        return $zone->methods()->where('is_active', true)->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMethod(array $data): ShippingMethod
    {
        /** @var ShippingMethod */
        return ShippingMethod::query()->create($this->validate($data, null))->load('zone:id,name');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMethod(ShippingMethod $method, array $data): ShippingMethod
    {
        $method->fill($this->validate($data, $method))->save();

        return $method->load('zone:id,name');
    }

    public function deleteMethod(ShippingMethod $method): void
    {
        $method->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?ShippingMethod $existing): array
    {
        $req = $existing === null ? 'required' : 'sometimes';

        $validated = validator($data, [
            'shipping_zone_id' => [$req, 'integer', Rule::exists('tenant.shipping_zones', 'id')],
            'name' => [$req, 'string', 'max:120'],
            'fulfillment_type' => [$req, Rule::in([ShippingMethod::COURIER, ShippingMethod::IN_HOUSE])],
            'courier_provider' => ['sometimes', 'nullable', 'string', 'max:64'],
            'cost' => [$req, 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999'],
            'estimated_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        $type = $validated['fulfillment_type'] ?? $existing?->fulfillment_type;
        $provider = array_key_exists('courier_provider', $validated) ? $validated['courier_provider'] : $existing?->courier_provider;

        if ($type === ShippingMethod::IN_HOUSE && filled($provider)) {
            throw ValidationException::withMessages(['courier_provider' => ['An in-house method has no courier provider.']]);
        }

        return $validated;
    }
}
