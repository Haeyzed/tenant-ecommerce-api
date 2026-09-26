<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Address;
use App\Modules\Customers\Models\Customer;
use App\Modules\Shipping\Http\ShippingPresenter;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Services\ShippingService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/shipping/methods (spec §36.4): the methods for a customer's own
 * address (?address_id=) or for a country and state.
 */
final class ShippingMethodController extends Controller
{
    public function index(Request $request, ShippingService $shipping, ShippingPresenter $presenter): JsonResponse
    {
        $validated = $request->validate([
            'address_id' => ['sometimes', 'integer'],
            'country_id' => ['required_without:address_id', 'integer'],
            'state_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        if (isset($validated['address_id'])) {
            $customer = $request->user();

            // Only the signed-in customer's own addresses.
            $address = $customer instanceof Customer
                ? Address::query()->where('customer_id', $customer->id)->whereKey((int) $validated['address_id'])->first()
                : null;

            if ($address === null) {
                return APIResponse::error('address_not_found', 'Address not found.', 404);
            }

            $target = ['country_id' => $address->country_id, 'state_id' => $address->state_id];
        } else {
            $target = ['country_id' => (int) $validated['country_id'], 'state_id' => isset($validated['state_id']) ? (int) $validated['state_id'] : null];
        }

        return APIResponse::success($shipping->getAvailableMethods($target)->map(static fn (ShippingMethod $m): array => $presenter->method($m, true))->values());
    }
}
