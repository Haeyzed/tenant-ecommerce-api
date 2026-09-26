<?php

declare(strict_types=1);

namespace App\Modules\Tax\Services;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tax\Models\TaxRate;
use App\Shared\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tax rates and the tax calculation (spec §35). Tax follows the customer's
 * address. A line's rate is chosen by its product's tax class: the state
 * rate, else the country rate, else none; "exempt" is never taxed. The tax
 * base is the line's net amount after discounts; amounts are rounded per
 * line (§38.5).
 */
final readonly class TaxService
{
    private const int SCALE = 10;

    public function __construct(private TenantSettingsService $settings) {}

    /**
     * @param  array{country_id?: int, state_id?: int, tax_class?: string, is_active?: bool}  $filters
     * @return Collection<int, TaxRate>
     */
    public function listTaxRates(array $filters = []): Collection
    {
        return TaxRate::query()
            ->when($filters['country_id'] ?? null, static fn ($q, $v) => $q->where('country_id', $v))
            ->when($filters['state_id'] ?? null, static fn ($q, $v) => $q->where('state_id', $v))
            ->when($filters['tax_class'] ?? null, static fn ($q, $v) => $q->where('tax_class', $v))
            ->when(array_key_exists('is_active', $filters), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('country_id')->orderBy('state_key')->orderBy('tax_class')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTaxRate(array $data): TaxRate
    {
        /** @var TaxRate */
        return TaxRate::query()->create($this->validate($data, null));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTaxRate(TaxRate $rate, array $data): TaxRate
    {
        $rate->fill($this->validate($data, $rate))->save();

        return $rate;
    }

    public function deleteTaxRate(TaxRate $rate): void
    {
        // Orders snapshot the rate and amount, so history never depends on the row.
        $rate->delete();
    }

    /**
     * @param  array{country_id?: int|null, state_id?: int|null}  $address
     */
    public function getTaxRateForAddress(array $address, string $taxClass): ?TaxRate
    {
        return $this->rateFrom($this->ratesFor($address), $address, $taxClass);
    }

    /**
     * Tax of priced lines and of net shipping.
     *
     * @param  list<array{amount: string, tax_class: string, origin?: Warehouse|null}>  $lines  amount = the line's net amount (line_net); origin = its fulfilling warehouse, else $origin
     * @param  array{country_id?: int|null, state_id?: int|null}  $address
     * @return array{lines: list<array{tax_rate_applied: string, tax_amount: string, tax_breakdown: array<string, string>|null}>, shipping_tax_amount: string, total: string}
     */
    public function calculateForLines(array $lines, array $address, ?Warehouse $origin = null, string $netShipping = '0', ?string $currency = null): array
    {
        $currency ??= (string) $this->settings->get('default_currency', 'USD');
        $inclusive = (bool) $this->settings->get('prices_include_tax', false);
        $gst = (bool) $this->settings->get('india_gst_enabled', false);
        $rates = $this->ratesFor($address);
        $total = Money::normalize(0);
        $results = [];

        foreach ($lines as $line) {
            $rate = $this->rateFrom($rates, $address, (string) $line['tax_class']);
            $percentage = $rate === null ? Money::normalize(0) : (string) $rate->rate_percentage;
            $amount = $this->taxOn((string) $line['amount'], $percentage, $inclusive, $currency);
            $total = Money::add($total, $amount);

            $results[] = [
                'tax_rate_applied' => $percentage,
                'tax_amount' => $amount,
                'tax_breakdown' => $gst ? $this->gstBreakdown($amount, $address, $line['origin'] ?? $origin, $currency) : null,
            ];
        }

        $shippingTax = Money::normalize(0);

        if ((bool) $this->settings->get('tax_shipping', false) && Money::isPositive(Money::normalize($netShipping))) {
            $rate = $this->rateFrom($rates, $address, 'standard');
            $shippingTax = $rate === null ? $shippingTax : $this->taxOn(Money::normalize($netShipping), (string) $rate->rate_percentage, $inclusive, $currency);
        }

        return ['lines' => $results, 'shipping_tax_amount' => $shippingTax, 'total' => Money::add($total, $shippingTax)];
    }

    /**
     * Exclusive prices add net × rate / 100; inclusive prices contain
     * net × rate / (100 + rate). Rounded half-up to the minor unit.
     */
    private function taxOn(string $net, string $rate, bool $inclusive, string $currency): string
    {
        if (! Money::isPositive($rate) || ! Money::isPositive($net)) {
            return Money::normalize(0);
        }

        $divisor = $inclusive ? bcadd('100', $rate, self::SCALE) : '100';

        return Money::round(bcdiv(bcmul($net, $rate, self::SCALE), $divisor, self::SCALE), $currency);
    }

    /**
     * India GST (§35.3): intra-state (the fulfilling warehouse's state is the
     * destination state) splits into CGST and SGST; otherwise IGST.
     *
     * @param  array{country_id?: int|null, state_id?: int|null}  $address
     * @return array<string, string>
     */
    private function gstBreakdown(string $amount, array $address, ?Warehouse $origin, string $currency): array
    {
        $destination = $address['state_id'] ?? null;

        if ($origin?->state_id === null || $destination === null || (int) $destination !== $origin->state_id) {
            return ['igst' => $amount];
        }

        $half = Money::round(bcdiv($amount, '2', self::SCALE), $currency);

        return ['cgst' => $half, 'sgst' => Money::sub($amount, $half)];
    }

    /**
     * @param  array{country_id?: int|null, state_id?: int|null}  $address
     * @return Collection<int, TaxRate>
     */
    private function ratesFor(array $address): Collection
    {
        $country = $address['country_id'] ?? null;

        return $country === null ? new Collection : TaxRate::query()->where('is_active', true)->where('country_id', (int) $country)->get();
    }

    /**
     * @param  Collection<int, TaxRate>  $rates  active rates of the address's country
     * @param  array{country_id?: int|null, state_id?: int|null}  $address
     */
    private function rateFrom(Collection $rates, array $address, string $taxClass): ?TaxRate
    {
        if ($taxClass === 'exempt') {
            return null;
        }

        $state = $address['state_id'] ?? null;
        $ofClass = $rates->where('tax_class', $taxClass);

        return ($state !== null ? $ofClass->firstWhere('state_id', (int) $state) : null)
            ?? $ofClass->first(static fn (TaxRate $r): bool => $r->state_id === null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ?TaxRate $existing): array
    {
        $req = $existing === null ? 'required' : 'sometimes';
        $country = $data['country_id'] ?? $existing?->country_id;
        $state = array_key_exists('state_id', $data) ? $data['state_id'] : $existing?->state_id;
        $class = $data['tax_class'] ?? $existing?->tax_class ?? 'standard';

        $validated = validator($data, [
            'name' => [$req, 'string', 'max:64'],
            'country_id' => [$req, 'integer', Rule::exists('landlord.countries', 'id')],
            'state_id' => ['sometimes', 'nullable', 'integer', Rule::exists('landlord.states', 'id')->where('country_id', $country)],
            'tax_class' => ['sometimes', Rule::in(TaxRate::CLASSES)],
            'rate_percentage' => [$req, 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        // One rate per region and class.
        $taken = TaxRate::query()->where('country_id', $country)->where('state_key', $state ?? 0)->where('tax_class', $class)
            ->when($existing !== null, static fn ($q) => $q->whereKeyNot($existing?->id))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['tax_class' => ['A rate for this region and tax class already exists.']]);
        }

        return $validated;
    }
}
