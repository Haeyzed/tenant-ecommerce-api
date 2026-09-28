<?php

declare(strict_types=1);

namespace App\Modules\GiftCards\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\GiftCards\Models\GiftCard;
use App\Modules\GiftCards\Models\GiftCardRedemption;
use App\Modules\GiftCards\Services\GiftCardService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gift cards in the back office (spec §46.5). Lists show the last four
 * characters; the full code is shown once, when staff issue a card.
 */
final class GiftCardController extends Controller
{
    public function __construct(private readonly GiftCardService $giftCards) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(GiftCard::STATUSES)],
            'search' => ['sometimes', 'string', 'max:255'],
            'customer_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->giftCards->listGiftCards($filters)->through(fn (GiftCard $c): array => $this->card($c)));
    }

    /**
     * Direct issue (goodwill). Body: initial_value, currency_code?, recipient_email?, recipient_message?, expires_at?
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $by */
        $by = $request->user();
        $card = $this->giftCards->issueGiftCard($request->only(['initial_value', 'currency_code', 'recipient_email', 'recipient_message', 'expires_at']), $by);

        return APIResponse::created([...$this->card($card), 'code' => $card->code], 'Gift card issued');
    }

    public function show(GiftCard $card): JsonResponse
    {
        $card = $this->giftCards->getGiftCard($card);

        return APIResponse::success([
            ...$this->card($card),
            'recipient_message' => $card->recipient_message,
            'purchaser' => $card->purchaser === null ? null : ['id' => $card->purchaser->id, 'name' => $card->purchaser->name, 'email' => $card->purchaser->email],
            'redemptions' => $card->redemptions->map(static fn (GiftCardRedemption $r): array => [
                'id' => $r->id,
                'order' => ['id' => $r->order_id, 'order_number' => $r->order?->order_number],
                'order_payment_id' => $r->order_payment_id,
                'amount' => (string) $r->amount,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function disable(GiftCard $card): JsonResponse
    {
        return APIResponse::success($this->card($this->giftCards->disableGiftCard($card)), 'Gift card disabled');
    }

    /**
     * @return array<string, mixed>
     */
    private function card(GiftCard $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->maskedCode(),
            'initial_value' => (string) $c->initial_value,
            'current_balance' => (string) $c->current_balance,
            'currency_code' => $c->currency_code,
            'status' => $c->status,
            'recipient_email' => $c->recipient_email,
            'source_order_id' => $c->source_order_id,
            'purchased_by_customer_id' => $c->purchased_by_customer_id,
            'issued_by_user_id' => $c->issued_by_user_id,
            'issued_at' => $c->issued_at->toIso8601String(),
            'expires_at' => $c->expires_at?->toIso8601String(),
        ];
    }
}
