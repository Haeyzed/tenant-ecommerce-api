<?php

declare(strict_types=1);

namespace App\Modules\Currency\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Currency\Http\CurrencyPresenter;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\TenantCurrency;
use App\Modules\Currency\Services\CurrencyService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The store's currencies and reference rates (spec §48.4). Rates are
 * 1 base = rate of the currency; a manual rate is never overwritten by the
 * provider.
 */
final class CurrencyController extends Controller
{
    public function __construct(
        private readonly CurrencyService $currencies,
        private readonly CurrencyPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $list = $this->currencies->listTenantCurrencies();
        $rates = ExchangeRate::query()->where('base_currency_code', $this->currencies->baseCurrency())->get()->keyBy('target_currency_code');

        return APIResponse::success([
            'base_currency' => $this->currencies->baseCurrency(),
            'currencies' => $list->map(fn (TenantCurrency $c): array => $this->presenter->currency($c, $rates, $this->currencies->offered($c->currency_code)))->values(),
        ]);
    }

    /**
     * Body: currency_code, display_symbol?, is_active?, rate? (a manual rate
     * in the same step). Adding a retired currency re-activates it.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currency_code' => ['required', 'string', 'size:3'],
            'display_symbol' => ['sometimes', 'nullable', 'string', 'max:8'],
            'is_active' => ['sometimes', 'boolean'],
            'rate' => ['sometimes', 'numeric', 'gt:0'],
        ]);

        $currency = $this->currencies->addTenantCurrency($validated['currency_code'], $request->boolean('is_active', true), $validated['display_symbol'] ?? null);

        if (isset($validated['rate'])) {
            $this->currencies->setRate($currency, (string) $validated['rate']);
        }

        return APIResponse::created($this->one($currency), 'Currency added');
    }

    public function deactivate(TenantCurrency $currency): JsonResponse
    {
        return APIResponse::success($this->one($this->currencies->deactivateTenantCurrency($currency)), 'Currency deactivated');
    }

    public function setBase(TenantCurrency $currency): JsonResponse
    {
        return APIResponse::success($this->one($this->currencies->setBaseCurrency($currency)), 'Store currency changed');
    }

    /**
     * Body: rate (1 base = rate of this currency).
     */
    public function setRate(Request $request, TenantCurrency $currency): JsonResponse
    {
        $rate = (string) $request->validate(['rate' => ['required', 'numeric', 'gt:0']])['rate'];
        $this->currencies->setRate($currency, $rate);

        return APIResponse::success($this->one($currency), 'Rate saved');
    }

    /**
     * Removes the rate; with a provider configured it is fetched again on
     * the next refresh.
     */
    public function clearRate(TenantCurrency $currency): JsonResponse
    {
        $this->currencies->clearRate($currency);

        return APIResponse::success($this->one($currency), 'Rate removed');
    }

    public function refreshRates(): JsonResponse
    {
        return APIResponse::success(['stored' => $this->currencies->refreshExchangeRates()], 'Rates refreshed');
    }

    /**
     * @return array<string, mixed>
     */
    private function one(TenantCurrency $currency): array
    {
        $rates = ExchangeRate::query()->where('base_currency_code', $this->currencies->baseCurrency())
            ->where('target_currency_code', $currency->currency_code)->get()->keyBy('target_currency_code');

        return $this->presenter->currency($currency->refresh(), $rates, $this->currencies->offered($currency->currency_code));
    }
}
