<?php

declare(strict_types=1);

use App\Shared\Http\BroadcastAuthController;
use Illuminate\Support\Facades\Route;

/*
| Private channel authorisation for platform users (spec §72.4).
*/

Route::middleware(['landlord.public', 'auth.as:platform', 'throttle:api'])
    ->post('broadcasting/auth', BroadcastAuthController::class)
    ->name('landlord.broadcasting.auth');
