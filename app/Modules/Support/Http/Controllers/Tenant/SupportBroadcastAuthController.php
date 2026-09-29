<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Support\Support\GuestSupportActor;
use App\Shared\Exceptions\ApiException;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Private-channel authorisation for customers and guests (spec §59.2,
 * §72.4): a signed-in customer, or a guest by X-Guest-Token. The channel
 * callbacks decide which conversations each may join.
 */
final class SupportBroadcastAuthController extends Controller
{
    public function __invoke(Request $request): mixed
    {
        $customer = $request->user('customer');
        $token = ResolveGuestToken::from($request);

        $actor = match (true) {
            $customer instanceof Customer => $customer,
            $token !== null => new GuestSupportActor($token),
            default => throw new ApiException('unauthenticated', 'Sign in, or send the X-Guest-Token header.', 401),
        };

        $request->setUserResolver(static fn () => $actor);

        return Broadcast::auth($request);
    }
}
