<?php

declare(strict_types=1);

namespace App\Modules\World\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * World reference data (spec §20): seeded once in the landlord database,
 * read from both contexts through the landlord connection and cached in the
 * landlord store (it is the same for every tenant).
 */
final class WorldService
{
    private const int TTL = 86400;

    /**
     * @return list<array{value: int, label: string, meta: array<string, mixed>}>
     */
    public function countries(): array
    {
        return $this->remember('countries', fn (): array => $this->table('countries')
            ->orderBy('name')
            ->get(['id', 'name', 'iso2', 'iso3', 'phone_code', 'emoji'])
            ->map(static fn (object $c): array => [
                'value' => (int) $c->id,
                'label' => (string) $c->name,
                'meta' => ['iso2' => $c->iso2, 'iso3' => $c->iso3, 'phone_code' => $c->phone_code, 'emoji' => $c->emoji],
            ])->all());
    }

    /**
     * @return list<array{value: int, label: string, meta: array<string, mixed>}>
     */
    public function states(int $countryId): array
    {
        return $this->remember('states:'.$countryId, fn (): array => $this->table('states')
            ->where('country_id', $countryId)
            ->orderBy('name')
            ->get(['id', 'name', 'country_id'])
            ->map(static fn (object $s): array => ['value' => (int) $s->id, 'label' => (string) $s->name, 'meta' => ['country_id' => (int) $s->country_id]])
            ->all());
    }

    /**
     * @return list<array{value: int, label: string, meta: array<string, mixed>}>
     */
    public function cities(int $stateId): array
    {
        return $this->remember('cities:'.$stateId, fn (): array => $this->table('cities')
            ->where('state_id', $stateId)
            ->orderBy('name')
            ->get(['id', 'name', 'state_id'])
            ->map(static fn (object $c): array => ['value' => (int) $c->id, 'label' => (string) $c->name, 'meta' => ['state_id' => (int) $c->state_id]])
            ->all());
    }

    /**
     * One entry per currency code.
     *
     * @return list<array{value: string, label: string, meta: array<string, mixed>}>
     */
    public function currencies(): array
    {
        return $this->remember('currencies', fn (): array => $this->table('currencies')
            ->orderBy('code')
            ->get(['code', 'name', 'symbol', 'precision'])
            ->unique('code')
            ->map(static fn (object $c): array => [
                'value' => (string) $c->code,
                'label' => $c->code.' - '.$c->name,
                'meta' => ['symbol' => $c->symbol, 'precision' => (int) $c->precision],
            ])->values()->all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function languages(): array
    {
        return $this->remember('languages', fn (): array => $this->table('languages')
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(static fn (object $l): array => ['value' => (string) $l->code, 'label' => (string) $l->name])
            ->all());
    }

    /**
     * IANA zone names (the World table holds one row per country and zone).
     *
     * @return list<array{value: string, label: string}>
     */
    public function timezones(): array
    {
        return $this->remember('timezones', fn (): array => $this->table('timezones')
            ->orderBy('name')
            ->distinct()
            ->pluck('name')
            ->map(static fn (string $name): array => ['value' => $name, 'label' => str_replace('_', ' ', $name)])
            ->all());
    }

    public function countryExists(int $countryId): bool
    {
        return in_array($countryId, array_column($this->countries(), 'value'), true);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $resolve
     * @return T
     */
    private function remember(string $key, callable $resolve): mixed
    {
        return Cache::store('landlord')->remember('world:'.$key, self::TTL, $resolve);
    }

    private function table(string $name): Builder
    {
        return DB::connection('landlord')->table((string) config("world.migrations.{$name}.table_name", $name));
    }
}
