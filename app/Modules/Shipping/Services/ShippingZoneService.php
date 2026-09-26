<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingZone;
use App\Modules\Shipping\Models\ShippingZoneRegion;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shipping zones and their regions (spec §36.1, §36.3). An address
 * resolves to the most specific match: a state region first, then a
 * whole-country region; among equals, the oldest active zone.
 */
final readonly class ShippingZoneService
{
    /**
     * @return Collection<int, ShippingZone>
     */
    public function listZones(): Collection
    {
        return ShippingZone::query()->with(['regions', 'methods'])->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data  name, is_active, regions[{country_id, state_id?}]
     */
    public function createZone(array $data): ShippingZone
    {
        $validated = $this->validate($data, true);

        return DB::connection('tenant')->transaction(function () use ($validated): ShippingZone {
            /** @var ShippingZone $zone */
            $zone = ShippingZone::query()->create(['name' => $validated['name'], 'is_active' => $validated['is_active'] ?? true]);
            $this->syncRegions($zone, $validated['regions']);

            return $zone->load(['regions', 'methods']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateZone(ShippingZone $zone, array $data): ShippingZone
    {
        $validated = $this->validate($data, false);

        DB::connection('tenant')->transaction(function () use ($zone, $validated): void {
            $zone->fill(array_intersect_key($validated, array_flip(['name', 'is_active'])))->save();

            if (array_key_exists('regions', $validated)) {
                $this->syncRegions($zone, $validated['regions']);
            }
        });

        return $zone->load(['regions', 'methods']);
    }

    /**
     * Refused while methods (including deleted ones, which orders keep
     * referencing) belong to the zone; deactivate it instead.
     */
    public function deleteZone(ShippingZone $zone): void
    {
        if (ShippingMethod::withTrashed()->where('shipping_zone_id', $zone->id)->exists()) {
            throw ApiException::unprocessable('zone_has_methods', 'This zone has shipping methods. Deactivate it instead.');
        }

        $zone->delete();
    }

    /**
     * @param  array{country_id?: int|null, state_id?: int|null}  $address
     */
    public function resolveZoneForAddress(array $address): ?ShippingZone
    {
        $country = $address['country_id'] ?? null;

        if ($country === null) {
            return null;
        }

        $state = $address['state_id'] ?? null;

        $region = ShippingZoneRegion::query()
            ->join('shipping_zones', 'shipping_zones.id', '=', 'shipping_zone_regions.shipping_zone_id')
            ->where('shipping_zones.is_active', true)
            ->where('shipping_zone_regions.country_id', (int) $country)
            ->whereIn('shipping_zone_regions.state_key', array_values(array_unique([$state === null ? 0 : (int) $state, 0])))
            // A state match (state_key > 0) outranks the whole country.
            ->orderByDesc('shipping_zone_regions.state_key')
            ->orderBy('shipping_zones.id')
            ->first(['shipping_zone_regions.shipping_zone_id']);

        return $region === null ? null : ShippingZone::query()->find($region->shipping_zone_id);
    }

    /**
     * @param  list<array{country_id: int, state_id?: int|null}>  $regions
     */
    private function syncRegions(ShippingZone $zone, array $regions): void
    {
        $zone->regions()->delete();

        foreach ($regions as $region) {
            $zone->regions()->create(['country_id' => (int) $region['country_id'], 'state_id' => isset($region['state_id']) ? (int) $region['state_id'] : null]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        $validated = validator($data, [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'regions' => [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:500'],
            'regions.*.country_id' => ['required', 'integer', Rule::exists('landlord.countries', 'id')],
            'regions.*.state_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();

        $seen = [];

        foreach ($validated['regions'] ?? [] as $index => $region) {
            $state = $region['state_id'] ?? null;

            if ($state !== null && ! DB::connection('landlord')->table('states')->where('id', $state)->where('country_id', $region['country_id'])->exists()) {
                throw ValidationException::withMessages(["regions.{$index}.state_id" => ['The state does not belong to the country.']]);
            }

            $key = $region['country_id'].':'.($state ?? 0);

            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["regions.{$index}" => ['Each region appears once per zone.']]);
            }

            $seen[$key] = true;
        }

        return $validated;
    }
}
