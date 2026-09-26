<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Private channel authorisation (spec §72.4), mounted on landlord domains
 * (guard platform) and tenant domains (guard staff). The actor is already
 * authenticated by auth.as; the channel callbacks decide access.
 */
final class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request): mixed
    {
        return Broadcast::auth($request);
    }
}
