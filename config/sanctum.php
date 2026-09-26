<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sanctum (spec §10.2)
|--------------------------------------------------------------------------
|
| Stateless personal access tokens only: no stateful SPA cookies, because
| every actor authenticates with a bearer token (CORS credentials are off).
|
*/

return [

    'stateful' => [],

    'guard' => [],

    'expiration' => env('SANCTUM_EXPIRATION') !== null ? (int) env('SANCTUM_EXPIRATION') : 60 * 24 * 30,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'tea_'),

    'middleware' => [],

];
