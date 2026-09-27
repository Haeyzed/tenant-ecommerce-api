<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Multi-currency (spec §48)
|--------------------------------------------------------------------------
|
| fx_provider (UD-19) supplies reference rates for the daily refresh:
|   none         nothing is fetched; staff set rates by hand (default)
|   open_er_api  open.er-api.com, free, no key, daily updates (attribution:
|                "Rates by Exchange Rate API", https://www.exchangerate-api.com)
| A manual rate is never overwritten by the provider. Rates only estimate
| prices without an explicit product price and convert baskets to the base
| currency for accounting; explicit prices are never repriced.
|
*/

return [

    'fx_provider' => env('FX_PROVIDER', 'none'),

    'providers' => [
        'open_er_api' => [
            'base_url' => env('FX_OPEN_ER_API_URL', 'https://open.er-api.com/v6'),
            'timeout' => (int) env('FX_HTTP_TIMEOUT', 10),
        ],
    ],

];
