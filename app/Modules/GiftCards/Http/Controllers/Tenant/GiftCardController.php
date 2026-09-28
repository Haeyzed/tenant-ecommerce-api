<?php

declare(strict_types=1);

namespace App\Modules\GiftCards\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\GiftCards\Services\GiftCardService;
use App\Modules\Orders\Http\OrderPresenter;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buying a gift card and checking a balance (spec §46.2, §46.5).
 */
final class GiftCardController extends Controller
{
    public function __construct(
        private readonly GiftCardService $giftCards,
        private readonly OrderPresenter $orders,
    ) {}

    /**
     * Body: amount, currency_code?, recipient_email?, recipient_message?, guest_email (guests), guest_name?.
     * The order is paid like any other (POST /api/orders/{order}/pay); the
     * card is issued when it is confirmed.
     */
    public function purchase(Request $request): JsonResponse
    {
        $user = $request->user();
        $order = $this->giftCards->purchase($user instanceof Customer ? $user : null, ResolveGuestToken::from($request),
            $request->all(), $request->header('Idempotency-Key'));

        return APIResponse::created($this->orders->order($order->load('items'), true, false), 'Gift card order created: pay it to receive the card');
    }

    public function balance(string $code): JsonResponse
    {
        return APIResponse::success($this->giftCards->checkBalance($code));
    }
}
