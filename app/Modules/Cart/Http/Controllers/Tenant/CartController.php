<?php

declare(strict_types=1);

namespace App\Modules\Cart\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Http\CartResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/cart (spec §38.7): the cart with its quote. An address
 * (?address_id= or ?country_id=&state_id=) and ?shipping_method_id= add
 * tax and shipping. Reading never creates a cart.
 */
final class CartController extends Controller
{
    public function show(Request $request, CartResponder $carts): JsonResponse
    {
        return $carts->respond($request, $carts->find($request));
    }
}
