<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * Storefront order access (spec §39.6): the customer's own orders, or a
 * guest order whose token matches X-Guest-Token. Anything else is 404.
 */
final class OrderAccess
{
    public static function assertCanView(Request $request, Order $order): void
    {
        $customer = $request->user();

        $allowed = $customer instanceof Customer
            ? $order->customer_id === $customer->id
            : $order->customer_id === null && $order->guest_token !== null
                && ($token = ResolveGuestToken::from($request)) !== null && hash_equals($order->guest_token, $token);

        if (! $allowed) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$order->id]);
        }
    }
}
