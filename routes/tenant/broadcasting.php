<?php

declare(strict_types=1);

use App\Shared\Http\BroadcastAuthController;
use Illuminate\Support\Facades\Route;

/*
| Private channel authorisation for tenant staff (spec §72.4). Customer and
| guest-token authorisation are added with the customer support module.
*/

Route::middleware(['tenant.public', 'auth.as:staff', 'throttle:api'])
    ->post('broadcasting/auth', BroadcastAuthController::class)
    ->name('tenant.broadcasting.auth');
