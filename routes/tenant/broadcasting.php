<?php

declare(strict_types=1);

use App\Shared\Http\BroadcastAuthController;
use Illuminate\Support\Facades\Route;

/*
| Private channel authorisation for tenant staff (spec §72.4). Customers and
| guests authorise at POST /api/support/broadcasting/auth (routes/tenant/support.php).
*/

Route::middleware(['tenant.public', 'auth.as:staff', 'throttle:api'])
    ->post('broadcasting/auth', BroadcastAuthController::class)
    ->name('tenant.broadcasting.auth');
