<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http;

use App\Modules\Orders\Http\OrderPresenter;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Pos\Models\PosRegister;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Models\PosSettings;
use App\Modules\Pos\Models\PosTerminalCharge;
use App\Modules\Pos\Services\PosSessionService;

/**
 * POS responses (spec §51.8). Terminal credentials are write-only: only
 * whether they are set is shown.
 */
final readonly class PosPresenter
{
    public function __construct(
        private OrderPresenter $orders,
        private PosSessionService $sessions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function register(PosRegister $register): array
    {
        return [
            'id' => $register->id,
            'name' => $register->name,
            'warehouse' => $register->relationLoaded('warehouse') && $register->warehouse !== null
                ? ['id' => $register->warehouse->id, 'name' => $register->warehouse->name] : ['id' => $register->warehouse_id],
            'terminal_provider' => $register->terminal_provider,
            'has_terminal_credentials' => $register->terminal_credentials !== null,
            'is_active' => $register->is_active,
            'created_at' => $register->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function session(PosSession $session, bool $summary = false): array
    {
        $user = static fn (mixed $u): ?array => $u === null ? null : ['id' => $u->id, 'name' => $u->name];

        return [
            'id' => $session->id,
            'register' => $session->relationLoaded('register') ? ['id' => $session->register->id, 'name' => $session->register->name] : ['id' => $session->pos_register_id],
            'opened_by' => $session->relationLoaded('openedBy') ? $user($session->openedBy) : ['id' => $session->opened_by_user_id],
            'closed_by' => $session->relationLoaded('closedBy') ? $user($session->closedBy) : ($session->closed_by_user_id === null ? null : ['id' => $session->closed_by_user_id]),
            'status' => $session->status,
            'opening_cash_float' => (string) $session->opening_cash_float,
            'closing_cash_float' => $session->closing_cash_float === null ? null : (string) $session->closing_cash_float,
            'expected_cash' => $session->expected_cash === null ? null : (string) $session->expected_cash,
            'cash_variance' => $session->cash_variance === null ? null : (string) $session->cash_variance,
            'opened_at' => $session->opened_at->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            ...($summary ? ['summary' => $this->sessions->getSessionSummary($session)] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function charge(PosTerminalCharge $charge): array
    {
        return [
            'reference' => $charge->reference,
            'register_id' => $charge->pos_register_id,
            'provider' => $charge->provider,
            'provider_reference' => $charge->provider_reference,
            'amount' => (string) $charge->amount,
            'currency_code' => $charge->currency_code,
            'status' => $charge->status,
            'card_last4' => $charge->card_last4,
            'failure_reason' => $charge->failure_reason,
            'used' => $charge->order_payment_id !== null,
            'created_at' => $charge->created_at?->toIso8601String(),
        ];
    }

    /**
     * The sale: the order as staff see it, with its tenders.
     *
     * @return array<string, mixed>
     */
    public function sale(Order $order): array
    {
        $order->loadMissing(['items.product', 'items.warehouse', 'redemptions']);

        return [
            ...$this->orders->order($order, true, true),
            'pos_session_id' => $order->pos_session_id,
            'payments' => OrderPayment::query()->with('recorder:id,name')->where('order_id', $order->id)->orderBy('id')->get()
                ->map(fn (OrderPayment $p): array => $this->orders->payment($p))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(PosSettings $settings): array
    {
        return [
            'default_warehouse_id' => $settings->default_warehouse_id,
            'default_customer_id' => $settings->default_customer_id,
            'default_cashier_user_id' => $settings->default_cashier_user_id,
            'products_per_page' => $settings->products_per_page,
            'touchscreen_keyboard_enabled' => $settings->touchscreen_keyboard_enabled,
            'table_management_enabled' => $settings->table_management_enabled,
            'send_sms_after_sale' => $settings->send_sms_after_sale,
            'cash_register_enabled' => $settings->cash_register_enabled,
            'print_receipt_by_default' => $settings->print_receipt_by_default,
            'play_sound_on_sale' => $settings->play_sound_on_sale,
            'enabled_payment_methods' => $settings->enabled_payment_methods,
        ];
    }
}
