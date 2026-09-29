<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers\Tenant\Concerns;

use App\Modules\Customers\Models\Customer;
use App\Modules\Support\Models\SupportConversation;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The storefront side of support (spec §59.4): a signed-in customer, or a
 * guest identified by X-Guest-Token. Anyone else's conversation is a 404.
 */
trait ResolvesSupportActor
{
    private function customer(Request $request): ?Customer
    {
        $customer = $request->user('customer');

        return $customer instanceof Customer ? $customer : null;
    }

    private function own(Request $request, SupportConversation $conversation): SupportConversation
    {
        $customer = $this->customer($request);
        $token = ResolveGuestToken::from($request);

        $mine = $customer !== null
            ? $conversation->customer_id === $customer->id
            : $conversation->customer_id === null && $token !== null && $conversation->guest_token !== null && hash_equals($conversation->guest_token, $token);

        return $mine ? $conversation : throw new NotFoundHttpException;
    }
}
