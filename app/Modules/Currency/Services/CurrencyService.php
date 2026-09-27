<?php

declare(strict_types=1);

namespace App\Modules\Currency\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ProductPrice;
use App\Modules\Currency\Models\TenantCurrency;
use App\Modules\Currency\Support\ExchangeRateProvider;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Settings\Services\StorefrontConfigService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The tenant's currencies, explicit market prices and reference rates
 * (spec §48.3).
 *
 * Rates are "1 base = rate target". A basket in another currency is priced
 * with that rate; the order then keeps the opposite direction as
 * exchange_rate_used (1 order currency = x base), which is what accounting
 * and the metrics multiply by. A non-base currency is offered only while
 * multi_currency is enabled, the currency is active and it has a rate:
 * without a rate an order could not be converted to the base currency.
 */
final readonly class CurrencyService
{
    /** Scale of rate arithmetic (the rate columns keep 12 places). */
    public const int SCALE = 12;

    public function __construct(
        private TenantSettingsService $settings,
        private FeatureAccessService $features,
        private ExchangeRateProvider $provider,
    ) {}

    public function baseCurrency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }

    /**
     * The base row, seeded at provisioning and by the defaults sync (§9.4):
     * inserted when the store has none, so the invariant always holds.
     */
    public function ensureBase(): TenantCurrency
    {
        $base = $this->baseCurrency();

        return DB::connection('tenant')->transaction(function () use ($base): TenantCurrency {
            $current = TenantCurrency::query()->where('is_base', true)->lockForUpdate()->first();

            if ($current !== null) {
                return $current;
            }

            /** @var TenantCurrency $row */
            $row = TenantCurrency::query()->firstOrNew(['currency_code' => $base]);
            $row->forceFill(['is_base' => true, 'is_active' => true])->save();

            return $row;
        });
    }

    /**
     * @return Collection<int, TenantCurrency>
     */
    public function listTenantCurrencies(): Collection
    {
        $this->ensureBase();

        return TenantCurrency::query()->orderByDesc('is_base')->orderBy('currency_code')->get();
    }

    /**
     * Adds a currency, or re-activates a retired one.
     */
    public function addTenantCurrency(string $code, bool $isActive = true, ?string $displaySymbol = null): TenantCurrency
    {
        $data = Validator::make(['currency_code' => strtoupper($code), 'display_symbol' => $displaySymbol], [
            'currency_code' => ['required', 'string', 'size:3', Rule::exists('landlord.currencies', 'code')],
            'display_symbol' => ['nullable', 'string', 'max:8'],
        ])->validate();

        $this->ensureBase();

        /** @var TenantCurrency $row */
        $row = TenantCurrency::query()->firstOrNew(['currency_code' => $data['currency_code']]);

        if ($row->exists && $row->is_base) {
            throw ApiException::unprocessable('currency_is_base', 'This is already the store currency.');
        }

        $row->display_symbol = $data['display_symbol'] ?? $row->display_symbol;
        $row->is_active = $isActive;
        $row->save();
        StorefrontConfigService::flush();

        return $row;
    }

    public function deactivateTenantCurrency(TenantCurrency $currency): TenantCurrency
    {
        if ($currency->is_base) {
            throw ApiException::unprocessable('currency_is_base', 'The store currency cannot be deactivated. Choose another base currency first.');
        }

        // Carts in it fall back to the base currency on their next quote.
        $currency->forceFill(['is_active' => false])->save();
        StorefrontConfigService::flush();

        return $currency;
    }

    /**
     * A-31: only before the first order or journal entry, because historical
     * base amounts would otherwise become ambiguous. Rates are relative to
     * the base, so they are cleared.
     */
    public function setBaseCurrency(TenantCurrency $currency): TenantCurrency
    {
        if ($currency->is_base) {
            return $currency;
        }

        if (! $currency->is_active) {
            throw ApiException::unprocessable('currency_inactive', 'Activate this currency before making it the store currency.');
        }

        if (DB::connection('tenant')->table('orders')->exists() || DB::connection('tenant')->table('journal_entries')->exists()) {
            throw ApiException::unprocessable('base_currency_locked', 'The store currency cannot change once orders or journal entries exist.');
        }

        DB::connection('tenant')->transaction(function () use ($currency): void {
            $previous = TenantCurrency::query()->where('is_base', true)->lockForUpdate()->first();
            $previous?->forceFill(['is_base' => false])->save();
            $currency->forceFill(['is_base' => true, 'is_active' => true])->save();

            ExchangeRate::query()->delete();
            $this->settings->set('default_currency', $currency->currency_code);
        });

        ActivityRecorder::tenant('currencies', 'Store currency changed to '.$currency->currency_code, $currency);
        StorefrontConfigService::flush();

        return $currency;
    }

    /**
     * 1 base = rate target; '1' for the base currency, null without a rate.
     */
    public function rateFor(string $currency): ?string
    {
        $currency = strtoupper($currency);
        $base = $this->baseCurrency();

        if ($currency === $base) {
            return '1';
        }

        $rate = ExchangeRate::query()->where('base_currency_code', $base)->where('target_currency_code', $currency)->value('rate');

        return $rate === null ? null : bcadd((string) $rate, '0', self::SCALE);
    }

    public function setRate(TenantCurrency $currency, string $rate): ExchangeRate
    {
        if ($currency->is_base) {
            throw ApiException::unprocessable('currency_is_base', 'The store currency has no rate.');
        }

        Validator::make(['rate' => $rate], ['rate' => ['required', 'numeric', 'gt:0', 'max:1000000000']])->validate();

        /** @var ExchangeRate $row */
        $row = ExchangeRate::query()->firstOrNew(['base_currency_code' => $this->baseCurrency(), 'target_currency_code' => $currency->currency_code]);
        $row->forceFill(['rate' => bcadd($rate, '0', self::SCALE), 'source' => ExchangeRate::MANUAL, 'fetched_at' => now()])->save();
        StorefrontConfigService::flush();

        return $row;
    }

    /**
     * Hands a manually set rate back to the provider (or removes it).
     */
    public function clearRate(TenantCurrency $currency): void
    {
        ExchangeRate::query()->where('base_currency_code', $this->baseCurrency())->where('target_currency_code', $currency->currency_code)->delete();
        StorefrontConfigService::flush();
    }

    /**
     * RefreshExchangeRates (§48.2): only pairs between the base and the
     * active currencies, and never a manual rate.
     *
     * @return int the number of rates stored
     */
    public function refreshExchangeRates(): int
    {
        if (! $this->provider->configured()) {
            return 0;
        }

        $base = $this->baseCurrency();
        $manual = ExchangeRate::query()->where('base_currency_code', $base)->where('source', ExchangeRate::MANUAL)->pluck('target_currency_code')->all();
        $targets = TenantCurrency::query()->where('is_active', true)->where('is_base', false)->whereNotIn('currency_code', $manual)->pluck('currency_code')->all();

        if ($targets === []) {
            return 0;
        }

        $stored = 0;

        foreach ($this->provider->fetch($base, array_values($targets)) as $target => $rate) {
            ExchangeRate::query()->updateOrCreate(
                ['base_currency_code' => $base, 'target_currency_code' => $target],
                ['rate' => $rate, 'source' => ExchangeRate::PROVIDER, 'fetched_at' => now()],
            );
            $stored++;
        }

        if ($stored > 0) {
            StorefrontConfigService::flush();
        }

        return $stored;
    }

    public function enabled(): bool
    {
        $tenant = tenant();

        return $tenant instanceof Tenant && $this->features->state($tenant, 'multi_currency') === ModuleState::Enabled;
    }

    /**
     * Whether the storefront may sell in the currency now.
     */
    public function offered(string $currency): bool
    {
        $currency = strtoupper($currency);

        if ($currency === $this->baseCurrency()) {
            return true;
        }

        return $this->enabled()
            && TenantCurrency::query()->where('currency_code', $currency)->where('is_active', true)->exists()
            && $this->rateFor($currency) !== null;
    }

    /**
     * The rate of an offered currency, or 422 currency_not_supported.
     */
    public function offeredRate(string $currency): string
    {
        if (! $this->offered($currency)) {
            throw ApiException::unprocessable('currency_not_supported', 'This store does not sell in '.strtoupper($currency).'.');
        }

        return (string) $this->rateFor($currency);
    }

    /**
     * Currencies the storefront offers now, base first.
     *
     * @return list<array{currency_code: string, display_symbol: string|null, is_base: bool}>
     */
    public function storefrontCurrencies(): array
    {
        return $this->listTenantCurrencies()
            ->filter(fn (TenantCurrency $c): bool => $c->is_base || ($c->is_active && $this->offered($c->currency_code)))
            ->map(static fn (TenantCurrency $c): array => ['currency_code' => $c->currency_code, 'display_symbol' => $c->display_symbol, 'is_base' => $c->is_base])
            ->values()->all();
    }

    // ---- Explicit prices -----------------------------------------------------------

    /**
     * @return Collection<int, ProductPrice>
     */
    public function listProductPrices(Product $product): Collection
    {
        return ProductPrice::query()->where('product_id', $product->id)->orderBy('currency_code')->orderBy('variant_key')->get();
    }

    /**
     * @param  array<string, mixed>  $data  currency_code, price, compare_at_price, product_variant_id
     */
    public function setProductPrice(Product $product, array $data, ?ProductPrice $existing = null): ProductPrice
    {
        $validated = Validator::make($data, [
            'currency_code' => [$existing === null ? 'required' : 'prohibited', 'string', 'size:3'],
            'product_variant_id' => [$existing === null ? 'sometimes' : 'prohibited', 'nullable', 'integer'],
            'price' => [$existing === null ? 'required' : 'sometimes', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999'],
            'compare_at_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4', 'max:99999999999999'],
        ])->validate();

        $currency = strtoupper((string) ($validated['currency_code'] ?? $existing?->currency_code));

        if ($existing === null) {
            $row = TenantCurrency::query()->where('currency_code', $currency)->first();

            if ($row === null || ! $row->is_active || $row->is_base) {
                throw ApiException::unprocessable('currency_not_supported', 'Add '.$currency.' as an active store currency first; the base price is the product price.');
            }

            $variantId = $validated['product_variant_id'] ?? null;

            if ($variantId !== null && ! ProductVariant::query()->where('product_id', $product->id)->whereKey($variantId)->exists()) {
                throw ApiException::unprocessable('validation_failed', 'That variant does not belong to this product.');
            }

            if (ProductPrice::query()->where('product_id', $product->id)->where('variant_key', $variantId ?? 0)->where('currency_code', $currency)->exists()) {
                throw ApiException::conflict('price_exists', 'This product already has a '.$currency.' price. Update it instead.');
            }

            $existing = new ProductPrice(['currency_code' => $currency]);
            $existing->forceFill(['product_id' => $product->id, 'product_variant_id' => $variantId]);
        }

        foreach (['price', 'compare_at_price'] as $field) {
            if (array_key_exists($field, $validated)) {
                $existing->{$field} = $validated[$field] === null ? null : Money::round((string) $validated[$field], $currency);
            }
        }

        $existing->save();

        return $existing;
    }

    public function deleteProductPrice(ProductPrice $price): void
    {
        $price->delete();
    }

    /**
     * {price, compare_at_price, is_estimated} of the catalogue price in the
     * currency (an explicit row, else converted at the rate); null when the
     * currency has no rate and no explicit price.
     *
     * @return array{price: string, compare_at_price: string|null, is_estimated: bool}|null
     */
    public function getPriceForCurrency(Product $product, string $currencyCode, ?ProductVariant $variant = null): ?array
    {
        $currencyCode = strtoupper($currencyCode);
        $explicit = $this->explicitPrice($product->id, $variant?->id, $currencyCode);

        if ($explicit !== null) {
            return ['price' => $explicit['price'], 'compare_at_price' => $explicit['compare_at_price'], 'is_estimated' => false];
        }

        $rate = $this->rateFor($currencyCode);

        if ($rate === null) {
            return null;
        }

        $compare = $variant?->compare_at_price ?? $product->compare_at_price;

        return [
            'price' => $this->fromBase((string) ($variant?->price ?? $product->price), $rate, $currencyCode),
            'compare_at_price' => $compare === null ? null : $this->fromBase((string) $compare, $rate, $currencyCode),
            'is_estimated' => $rate !== '1',
        ];
    }

    /**
     * The explicit price of a variant, else of its product.
     *
     * @return array{price: string, compare_at_price: string|null}|null
     */
    public function explicitPrice(int $productId, ?int $variantId, string $currency): ?array
    {
        $row = ProductPrice::query()->where('product_id', $productId)->where('currency_code', strtoupper($currency))
            ->whereIn('variant_key', $variantId === null ? [0] : [$variantId, 0])
            ->orderByDesc('variant_key')->first(['price', 'compare_at_price']);

        return $row === null ? null : [
            'price' => Money::normalize((string) $row->price),
            'compare_at_price' => $row->compare_at_price === null ? null : Money::normalize((string) $row->compare_at_price),
        ];
    }

    /**
     * Explicit prices of many products at once (storefront lists).
     *
     * @param  list<int>  $productIds
     * @return array<string, array{price: string, compare_at_price: string|null}> keyed "product_id:variant_key"
     */
    public function explicitPrices(array $productIds, string $currency): array
    {
        if ($productIds === []) {
            return [];
        }

        return ProductPrice::query()->whereIn('product_id', $productIds)->where('currency_code', strtoupper($currency))
            ->get(['product_id', 'variant_key', 'price', 'compare_at_price'])
            ->mapWithKeys(static fn (ProductPrice $p): array => [$p->product_id.':'.$p->getAttribute('variant_key') => [
                'price' => Money::normalize((string) $p->price),
                'compare_at_price' => $p->compare_at_price === null ? null : Money::normalize((string) $p->compare_at_price),
            ]])->all();
    }

    // ---- Conversion ---------------------------------------------------------------------

    /**
     * A base amount in the target currency (1 base = rate target), rounded
     * to the target's minor unit.
     */
    public function fromBase(string $amount, string $rate, string $currency): string
    {
        return Money::round(bcmul($amount, $rate, self::SCALE), $currency);
    }

    /**
     * The exchange_rate_used of a record priced at a basket rate: 1 record
     * currency = x base.
     */
    public static function toBaseRate(string $rate): string
    {
        return bcdiv('1', $rate, self::SCALE);
    }

    /**
     * convertToBaseCurrency (§48.3): with the rate already captured on a
     * record (1 record currency = x base) when given, else the current
     * reference rate. Rounded to the base currency.
     */
    public function convertToBaseCurrency(string $amount, string $fromCurrency, ?string $toBaseRate = null): string
    {
        $base = $this->baseCurrency();

        if (strtoupper($fromCurrency) === $base) {
            return Money::round($amount, $base);
        }

        if ($toBaseRate !== null) {
            return Money::round(bcmul($amount, $toBaseRate, self::SCALE), $base);
        }

        $rate = $this->rateFor($fromCurrency) ?? throw ApiException::unprocessable('exchange_rate_unavailable', 'No exchange rate for '.strtoupper($fromCurrency).'.');

        return Money::round(bcdiv($amount, $rate, self::SCALE), $base);
    }

    /**
     * Outstanding balances by currency (§48.3): unpaid parts of confirmed,
     * non-cancelled, non-test orders, with their base value at each order's
     * captured rate. Gift cards and installments join when those modules
     * exist.
     *
     * @return array{base_currency: string, currencies: list<array{currency_code: string, orders: int, outstanding: string, base_equivalent: string}>, base_total: string}
     */
    public function getCurrencyExposure(): array
    {
        $paid = DB::connection('tenant')->table('order_payments')->where('status', 'successful')
            ->groupBy('order_id')->selectRaw('order_id, SUM(amount_paid) as net_paid');

        $rows = DB::connection('tenant')->table('orders as o')
            ->leftJoinSub($paid, 'paid', 'paid.order_id', '=', 'o.id')
            ->whereNotNull('o.confirmed_at')->where('o.status', '!=', 'cancelled')->where('o.is_test', false)
            ->whereRaw('o.total - COALESCE(paid.net_paid, 0) > 0')
            ->groupBy('o.currency_code')
            ->selectRaw('o.currency_code, COUNT(*) as orders, SUM(o.total - COALESCE(paid.net_paid, 0)) as outstanding,'
                .' SUM((o.total - COALESCE(paid.net_paid, 0)) * COALESCE(o.exchange_rate_used, 1)) as base_equivalent')
            ->orderBy('o.currency_code')->get();

        $base = $this->baseCurrency();
        $total = Money::normalize(0);
        $currencies = [];

        foreach ($rows as $row) {
            $equivalent = Money::round((string) $row->base_equivalent, $base);
            $total = Money::add($total, $equivalent);
            $currencies[] = [
                'currency_code' => (string) $row->currency_code,
                'orders' => (int) $row->orders,
                'outstanding' => Money::round((string) $row->outstanding, (string) $row->currency_code),
                'base_equivalent' => $equivalent,
            ];
        }

        return ['base_currency' => $base, 'currencies' => $currencies, 'base_total' => $total];
    }
}
