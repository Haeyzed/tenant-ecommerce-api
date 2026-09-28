<?php

declare(strict_types=1);

namespace App\Modules\GiftCards\Services;

use App\Modules\Accounting\Support\AccountingOutbox;
use App\Modules\Cart\Models\Cart;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Customers\Models\Customer;
use App\Modules\GiftCards\Models\GiftCard;
use App\Modules\GiftCards\Models\GiftCardRedemption;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Payments\Support\PaymentPostings;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Gift cards (spec §46). Redeeming is a payment, never a discount: a locked
 * card row, a gift_card order payment and a redemption row move together.
 * Reversals (a cancelled order, a refunded gift-card payment) credit the
 * card back and reactivate a redeemed card.
 */
final readonly class GiftCardService
{
    /** Unambiguous characters: no 0/O, 1/I/L. */
    private const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const string MAX_AMOUNT = '10000000';

    public function __construct(
        private NotificationDispatchService $notifications,
        private PaymentPostings $postings,
        private AccountingOutbox $outbox,
        private CurrencyService $currencies,
        private TenantSettingsService $settings,
        private FeatureAccessService $features,
    ) {}

    public function enabled(): bool
    {
        $tenant = tenant();

        return $tenant instanceof Tenant && $this->features->state($tenant, 'gift_cards') === ModuleState::Enabled;
    }

    /**
     * A new active card: from a confirmed purchase, or issued directly by
     * staff (goodwill). gift_card.issued goes to the recipient, else the
     * purchaser.
     *
     * @param  array<string, mixed>  $data  initial_value, currency_code?, recipient_email?, recipient_message?, expires_at?, purchased_by_customer_id?, source_order_id?
     */
    public function issueGiftCard(array $data, ?User $by = null): GiftCard
    {
        $validated = Validator::make($data, [
            'initial_value' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:'.self::MAX_AMOUNT],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'recipient_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'recipient_message' => ['sometimes', 'nullable', 'string', 'max:500'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'purchased_by_customer_id' => ['sometimes', 'nullable', 'integer'],
            'source_order_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();

        $currency = strtoupper((string) ($validated['currency_code'] ?? $this->currencies->baseCurrency()));

        if ($currency !== $this->currencies->baseCurrency() && $this->currencies->rateFor($currency) === null) {
            throw ApiException::unprocessable('currency_not_supported', 'Issue cards in the store currency or a currency with a rate.');
        }

        $value = Money::round(Money::normalize((string) $validated['initial_value']), $currency);

        $card = new GiftCard;
        $card->forceFill([
            'code' => $this->newCode(),
            'initial_value' => $value,
            'current_balance' => $value,
            'currency_code' => $currency,
            'purchased_by_customer_id' => $validated['purchased_by_customer_id'] ?? null,
            'source_order_id' => $validated['source_order_id'] ?? null,
            'recipient_email' => isset($validated['recipient_email']) ? strtolower((string) $validated['recipient_email']) : null,
            'recipient_message' => $validated['recipient_message'] ?? null,
            'status' => GiftCard::ACTIVE,
            'issued_by_user_id' => $by?->id,
            'issued_at' => now(),
            'expires_at' => $validated['expires_at'] ?? null,
        ])->save();

        $this->notifyIssued($card);

        return $card;
    }

    /**
     * POST /api/gift-cards/purchase (§46.2): a gift_card_purchase order with
     * one non-product line, no shipping and no tax (A-30), paid through the
     * normal flow; the card is issued on confirmation.
     *
     * @param  array<string, mixed>  $data  amount, currency_code?, recipient_email?, recipient_message?, guest_email?, guest_name?
     */
    public function purchase(?Customer $customer, ?string $guestToken, array $data, ?string $idempotencyKey = null): Order
    {
        if ($idempotencyKey !== null && ($existing = Order::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }

        $validated = Validator::make($data, [
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:'.self::MAX_AMOUNT],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'recipient_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'recipient_message' => ['sometimes', 'nullable', 'string', 'max:500'],
            'guest_email' => [$customer === null ? 'required' : 'prohibited', 'email:rfc', 'max:255'],
            'guest_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ])->validate();

        if ($customer === null && $guestToken === null) {
            throw ApiException::unprocessable('guest_token_required', 'Send X-Guest-Token (from the cart) or sign in.');
        }

        $currency = strtoupper((string) ($validated['currency_code'] ?? $this->currencies->baseCurrency()));
        $rate = $this->currencies->offeredRate($currency);
        $amount = Money::round(Money::normalize((string) $validated['amount']), $currency);

        return DB::connection('tenant')->transaction(fn (): Order => app(OrderService::class)->createOrder([
            'order_source' => 'online',
            'order_type' => 'gift_card_purchase',
            'customer' => $customer,
            'guest_token' => $customer === null ? $guestToken : null,
            'customer_name' => $customer?->name ?? ($validated['guest_name'] ?? null),
            'customer_email' => $customer?->email ?? $validated['guest_email'],
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'lines' => [[
                'product' => null,
                'name' => 'Gift card',
                'quantity' => '1',
                'unit_price' => $amount,
                'price_source' => 'base',
                'line_total' => $amount,
                'meta' => ['gift_card' => [
                    'recipient_email' => isset($validated['recipient_email']) ? strtolower((string) $validated['recipient_email']) : null,
                    'recipient_message' => $validated['recipient_message'] ?? null,
                ]],
            ]],
            'totals' => ['subtotal' => $amount, 'total' => $amount],
            'idempotency_key' => $idempotencyKey,
            'expires' => true,
        ]));
    }

    /**
     * The confirmation hook (§46.2 step 3): one card per purchase order,
     * none for a test order.
     */
    public function issueForOrder(Order $order): void
    {
        if ($order->order_type !== 'gift_card_purchase' || $order->is_test || GiftCard::query()->where('source_order_id', $order->id)->exists()) {
            return;
        }

        $details = (array) (($order->items()->first()?->meta ?? [])['gift_card'] ?? []);

        $this->issueGiftCard([
            'initial_value' => (string) $order->total,
            'currency_code' => $order->currency_code,
            'recipient_email' => $details['recipient_email'] ?? null,
            'recipient_message' => $details['recipient_message'] ?? null,
            'purchased_by_customer_id' => $order->customer_id,
            'source_order_id' => $order->id,
        ]);
    }

    /**
     * {valid, balance, applicable_amount, reason} for the cart (§46.4).
     *
     * @return array{valid: bool, balance: string|null, currency_code: string|null, applicable_amount: string, reason: string|null}
     */
    public function validateForCart(string $code, Cart $cart, ?string $total = null): array
    {
        $card = GiftCard::query()->where('code', strtoupper(trim($code)))->first();

        return $this->check($card, strtoupper($cart->currency_code), $total);
    }

    /**
     * @return array{valid: bool, balance: string|null, currency_code: string|null, applicable_amount: string, reason: string|null}
     */
    public function check(?GiftCard $card, string $currency, ?string $total = null): array
    {
        $reason = match (true) {
            $card === null => 'not_found',
            // A test order is never paid with a real card (§40.8).
            $this->testMode() => 'test_mode',
            $card->status === GiftCard::DISABLED => 'disabled',
            $card->status === GiftCard::REDEEMED || ! Money::isPositive((string) $card->current_balance) => 'no_balance',
            ! $card->isUsable() => 'expired',
            $card->currency_code !== $currency => 'gift_card_currency_mismatch',
            default => null,
        };

        $balance = $card === null ? null : Money::normalize((string) $card->current_balance);

        return [
            'valid' => $reason === null,
            'balance' => in_array($reason, ['not_found', 'test_mode'], true) ? null : $balance,
            'currency_code' => $card?->currency_code,
            'applicable_amount' => $reason === null && $total !== null ? Money::min((string) $balance, Money::normalize($total)) : Money::normalize(0),
            'reason' => $reason,
        ];
    }

    public function applyToCart(Cart $cart, string $code): void
    {
        $card = GiftCard::query()->where('code', strtoupper(trim($code)))->first();
        $result = $this->check($card, strtoupper($cart->currency_code));

        if (! $result['valid']) {
            throw ApiException::unprocessable($result['reason'] === 'gift_card_currency_mismatch' ? 'gift_card_currency_mismatch' : 'gift_card_invalid',
                'This gift card cannot be used on this cart.', ['reason' => $result['reason']]);
        }

        $cart->forceFill(['gift_card_id' => $card?->id, 'last_activity_at' => now()])->save();
    }

    public function removeFromCart(Cart $cart): void
    {
        $cart->forceFill(['gift_card_id' => null, 'last_activity_at' => now()])->save();
    }

    /**
     * Checkout step 11.4 (§38.6): under the card lock, the balance and status
     * are re-checked; a gift_card payment row and a redemption row are
     * written and the balance falls.
     */
    public function redeemGiftCard(GiftCard $card, Order $order, string $amount): OrderPayment
    {
        return DB::connection('tenant')->transaction(function () use ($card, $order, $amount): OrderPayment {
            /** @var GiftCard $locked */
            $locked = GiftCard::query()->lockForUpdate()->findOrFail($card->id);
            $amount = Money::normalize($amount);
            $check = $this->check($locked, $order->currency_code, $amount);

            if (! $check['valid'] || Money::cmp($check['applicable_amount'], $amount) < 0) {
                throw ApiException::conflict('gift_card_changed', 'The gift card balance changed. Review the cart.', ['reason' => $check['reason']]);
            }

            $payment = new OrderPayment;
            $payment->forceFill([
                'order_id' => $order->id,
                'kind' => OrderPayment::PAYMENT,
                'payment_method' => 'gift_card',
                'mode' => OrderPaymentService::modeOf($order),
                'status' => OrderPayment::SUCCESSFUL,
                'reference' => 'GC-'.$order->order_number.'-'.Str::upper(Str::random(6)),
                'amount_due' => $amount,
                'amount_paid' => $amount,
                'currency_code' => $order->currency_code,
                'paid_at' => now(),
                'meta' => ['gift_card' => $locked->maskedCode()],
            ])->save();

            $balance = Money::sub((string) $locked->current_balance, $amount);
            $locked->forceFill(['current_balance' => $balance, 'status' => Money::isPositive($balance) ? GiftCard::ACTIVE : GiftCard::REDEEMED])->save();
            $this->writeRedemption($locked, $order->id, $payment->id, $amount);

            Order::query()->whereKey($order->id)->update(['gift_card_amount_applied' => DB::raw('gift_card_amount_applied + '.$amount)]);
            $this->postings->payment($payment);

            return $payment;
        });
    }

    /**
     * A refunded gift-card payment (§40.4): the refund row's amount goes
     * back onto the card it came from. The caller holds the transaction.
     */
    public function reverseRedemption(OrderPayment $refund): void
    {
        $original = $refund->refund_of_order_payment_id === null ? null : OrderPayment::query()->find($refund->refund_of_order_payment_id);
        $redemption = $original === null ? null : GiftCardRedemption::query()->where('order_payment_id', $original->id)->where('amount', '>', 0)->first();

        if ($redemption === null) {
            return;
        }

        $this->credit((int) $redemption->gift_card_id, $refund->order_id, $refund->id, Money::sub('0', (string) $refund->amount_paid));
    }

    /**
     * The cancellation hook (§46.3): every gift-card payment still standing
     * goes back onto its card. The sale is reversed too, so the payment's
     * own posting is reversed rather than posted as a refund.
     */
    public function reverseForCancelledOrder(Order $order): void
    {
        $payments = OrderPayment::query()->where('order_id', $order->id)->where('kind', OrderPayment::PAYMENT)
            ->where('payment_method', 'gift_card')->where('status', OrderPayment::SUCCESSFUL)->orderBy('id')->get();

        foreach ($payments as $payment) {
            $refunded = Money::normalize((string) OrderPayment::query()->where('refund_of_order_payment_id', $payment->id)
                ->where('status', OrderPayment::SUCCESSFUL)->sum('amount_paid'));
            $remaining = Money::add((string) $payment->amount_paid, $refunded);

            if (! Money::isPositive($remaining)) {
                continue;
            }

            $row = new OrderPayment;
            $row->forceFill([
                'order_id' => $order->id,
                'kind' => OrderPayment::REFUND,
                'payment_method' => 'gift_card',
                'mode' => $payment->mode,
                'status' => OrderPayment::SUCCESSFUL,
                'reference' => 'GCR-'.Str::upper(Str::random(12)),
                'amount_paid' => Money::sub('0', $remaining),
                'currency_code' => $payment->currency_code,
                'refund_of_order_payment_id' => $payment->id,
                'paid_at' => now(),
                'notes' => 'Order cancelled: returned to the gift card',
            ])->save();

            $this->reverseRedemption($row);

            $current = $this->outbox->currentKey('order_payment:'.$payment->id);

            if ($current !== null) {
                $this->outbox->record('reversePosting', $payment, now(), 'order_payment_reversal:'.$payment->id.':cancelled', ['posting_key' => $current, 'reason' => 'Order cancelled']);
            }
        }
    }

    /**
     * @return array{balance: string, currency_code: string, status: string, expires_at: string|null}
     */
    public function checkBalance(string $code): array
    {
        $card = GiftCard::query()->where('code', strtoupper(trim($code)))->first()
            ?? throw new ApiException('gift_card_not_found', 'No gift card with this code.', 404);

        return [
            'balance' => Money::normalize((string) $card->current_balance),
            'currency_code' => $card->currency_code,
            'status' => $card->isUsable() || $card->status !== GiftCard::ACTIVE ? $card->status : GiftCard::EXPIRED,
            'expires_at' => $card->expires_at?->toIso8601String(),
        ];
    }

    public function disableGiftCard(GiftCard $card): GiftCard
    {
        $card->forceFill(['status' => GiftCard::DISABLED])->save();

        return $card;
    }

    public function getGiftCard(GiftCard $card): GiftCard
    {
        return $card->load(['redemptions.order:id,order_number', 'purchaser:id,name,email']);
    }

    /**
     * @param  array{status?: string, search?: string, customer_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, GiftCard>
     */
    public function listGiftCards(array $filters = []): LengthAwarePaginator
    {
        return GiftCard::query()
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['customer_id']), static fn ($q) => $q->where('purchased_by_customer_id', $filters['customer_id']))
            ->when(isset($filters['search']), static fn ($q) => $q->where(static fn ($w) => $w
                ->where('code', strtoupper((string) $filters['search']))->orWhere('recipient_email', strtolower((string) $filters['search']))))
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * ExpireGiftCards (§46.3).
     */
    public function expireGiftCards(): int
    {
        return GiftCard::query()->where('status', GiftCard::ACTIVE)->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->update(['status' => GiftCard::EXPIRED, 'updated_at' => now()]);
    }

    /**
     * New orders are test orders while the store is in test mode (§40.8).
     */
    private function testMode(): bool
    {
        return (string) $this->settings->get('payment_mode', 'test') === 'test';
    }

    private function credit(int $cardId, int $orderId, int $paymentId, string $amount): void
    {
        /** @var GiftCard $locked */
        $locked = GiftCard::query()->lockForUpdate()->findOrFail($cardId);
        $balance = Money::add((string) $locked->current_balance, $amount);

        $locked->forceFill([
            'current_balance' => Money::min($balance, (string) $locked->initial_value),
            'status' => $locked->status === GiftCard::REDEEMED ? GiftCard::ACTIVE : $locked->status,
        ])->save();

        $this->writeRedemption($locked, $orderId, $paymentId, Money::sub('0', $amount));
        Order::query()->whereKey($orderId)->update(['gift_card_amount_applied' => DB::raw('GREATEST(gift_card_amount_applied - '.$amount.', 0)')]);
    }

    private function writeRedemption(GiftCard $card, int $orderId, int $paymentId, string $amount): void
    {
        $row = new GiftCardRedemption;
        $row->forceFill(['gift_card_id' => $card->id, 'order_id' => $orderId, 'order_payment_id' => $paymentId, 'amount' => $amount])->save();
    }

    private function newCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < 16; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (GiftCard::query()->where('code', $code)->exists());

        return $code;
    }

    private function notifyIssued(GiftCard $card): void
    {
        $purchaser = $card->purchased_by_customer_id === null ? null : Customer::query()->find($card->purchased_by_customer_id);
        $email = $card->recipient_email ?? $purchaser?->email;

        if ($email === null && $card->source_order_id !== null) {
            $email = Order::withTrashed()->whereKey($card->source_order_id)->value('customer_email');
        }

        if ($email === null) {
            return;
        }

        $this->notifications->dispatch('gift_card.issued', Notification::route('mail', $email), [
            'amount' => Money::format((string) $card->initial_value, $card->currency_code),
            'code' => $card->code,
            'sender_name' => $purchaser?->name ?? (string) $this->settings->get('store_name', ''),
            'message' => (string) ($card->recipient_message ?? ''),
        ]);
    }
}
