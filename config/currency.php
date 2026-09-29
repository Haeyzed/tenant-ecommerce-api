<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Multi-currency (spec §48)
|--------------------------------------------------------------------------
|
| fx_provider (UD-19) supplies reference rates for the daily refresh:
|   none               nothing is fetched; staff set rates by hand (default)
|   openexchangerates  openexchangerates.org, the recommended provider:
|                      hourly rates for every currency including NGN, GHS
|                      and KES; needs OPENEXCHANGERATES_APP_ID. Rates are
|                      read against USD (every plan allows it) and crossed
|                      to the tenant's base; one response is shared by all
|                      tenants for cache_minutes, so the plan's request
|                      quota does not grow with the number of tenants.
|   open_er_api        open.er-api.com, free, no key, daily updates
|                      (attribution: "Rates by Exchange Rate API",
|                      https://www.exchangerate-api.com)
| A manual rate is never overwritten by the provider. Provider rates carry
| the tenant's exchange_rate_margin_percent. Rates only estimate prices
| without an explicit product price and convert baskets to the base
| currency for accounting; explicit prices are never repriced.
|
*/

return [

    'fx_provider' => env('FX_PROVIDER', 'none'),

    'providers' => [
        'openexchangerates' => [
            'base_url' => env('OPENEXCHANGERATES_URL', 'https://openexchangerates.org/api'),
            'app_id' => env('OPENEXCHANGERATES_APP_ID'),
            'cache_minutes' => (int) env('OPENEXCHANGERATES_CACHE_MINUTES', 60),
            'timeout' => (int) env('FX_HTTP_TIMEOUT', 10),
        ],
        'open_er_api' => [
            'base_url' => env('FX_OPEN_ER_API_URL', 'https://open.er-api.com/v6'),
            'timeout' => (int) env('FX_HTTP_TIMEOUT', 10),
        ],
    ],

];
