<?php

declare(strict_types=1);

namespace App\Modules\RewardPoints\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\RewardPoints\Models\RewardPointSettings;
use App\Modules\RewardPoints\Models\RewardPointTransaction;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The loyalty programme (spec §54). Points are earned once per completed
 * order on its net merchandise amount in the base currency (A-37), redeemed
 * at checkout as an order-level discount, restored on cancellation, and
 * expire oldest-first under A-38. Balances are locked rows; every change is
 * a transaction row with the balance after it.
 */
final readonly class RewardPointService
{
    public function __construct(
        private FeatureAccessService $features,
        private TenantSettingsService $settings,
    ) {}

    /**
     * The singleton settings row, created with the defaults on first read.
     */
    public function getSettings(): RewardPointSettings
    {
        /** @var RewardPointSettings */
        return RewardPointSettings::query()->first() ?? RewardPointSettings::query()->create([])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(array $data): RewardPointSettings
    {
        $validated = Validator::make($data, [
            'is_active' => ['sometimes', 'boolean'],
            'amount_per_point' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,4'],
            'minimum_order_amount_to_earn' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'point_expiry_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'redeem_amount_per_point' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,4'],
            'minimum_order_total_to_redeem' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'minimum_redeem_points' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'maximum_redeem_points_per_order' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();

        $settings = $this->getSettings();
        $settings->forceFill($validated)->save();

        return $settings;
    }

    /**
     * Both gates of §54: the feature and the programme switch.
     */
    public function active(): bool
    {
        $tenant = tenant();

        return $tenant instanceof Tenant && $this->features->state($tenant, 'reward_points') === ModuleState::Enabled && $this->getSettings()->is_active;
    }

    public function getBalance(Customer $customer): int
    {
        return (int) (DB::connection('tenant')->table('customer_reward_points')->where('customer_id', $customer->id)->value('points_balance') ?? 0);
    }

    /**
     * {valid, reason, discount} for redeeming points against an order total
     * (before the discount) in a basket currency (§54.2). rate: 1 base =
     * rate basket.
     *
     * @return array{valid: bool, reason: string|null, discount: string, points: int}
     */
    public function validateRedemption(Customer $customer, int $points, string $orderTotal, string $currency, string $rate = '1'): array
    {
        $settings = $this->getSettings();
        $baseTotal = bcdiv(Money::normalize($orderTotal), $rate, 4);

        $reason = match (true) {
            ! $this->active() => 'program_inactive',
            // New orders are test orders in test mode: they neither earn nor redeem (§40.8).
            (string) $this->settings->get('payment_mode', 'test') === 'test' => 'test_mode',
            $points < 1 => 'invalid_points',
            $points > $this->getBalance($customer) => 'insufficient_points',
            $settings->minimum_redeem_points !== null && $points < $settings->minimum_redeem_points => 'below_minimum_points',
            $settings->maximum_redeem_points_per_order !== null && $points > $settings->maximum_redeem_points_per_order => 'above_maximum_points',
            $settings->minimum_order_total_to_redeem !== null && Money::cmp($baseTotal, (string) $settings->minimum_order_total_to_redeem) < 0 => 'order_total_too_low',
            default => null,
        };

        if ($reason !== null) {
            return ['valid' => false, 'reason' => $reason, 'discount' => Money::normalize(0), 'points' => 0];
        }

        $value = Money::round(bcmul(bcmul((string) $points, (string) $settings->redeem_amount_per_point, 12), $rate, 12), $currency);

        return ['valid' => true, 'reason' => null, 'discount' => Money::min($value, Money::normalize($orderTotal)), 'points' => $points];
    }

    /**
     * Checkout step 11.5 (§38.6): the points leave the balance under its
     * lock, re-checked. The discount is already on the order.
     */
    public function redeemPoints(Order $order, int $points): void
    {
        if ($points < 1 || $order->customer_id === null) {
            return;
        }

        if ($order->is_test) {
            throw ApiException::unprocessable('reward_points_invalid', 'Points cannot be used on a test order.', ['reason' => 'test_mode']);
        }

        DB::connection('tenant')->transaction(function () use ($order, $points): void {
            $balance = $this->lockBalance((int) $order->customer_id);

            if ($points > $balance) {
                throw ApiException::conflict('reward_points_changed', 'Your points balance changed. Review the cart.');
            }

            $this->write((int) $order->customer_id, $order->id, RewardPointTransaction::REDEEMED, -$points, $balance - $points, 'Redeemed on order '.$order->order_number);
        });
    }

    /**
     * The cancellation hook (§54.2): redeemed points come back once, as an
     * adjustment.
     */
    public function restorePoints(Order $order): void
    {
        $redeemed = (int) RewardPointTransaction::query()->where('order_id', $order->id)->where('type', RewardPointTransaction::REDEEMED)->sum('points');
        $restored = (int) RewardPointTransaction::query()->where('order_id', $order->id)->where('type', RewardPointTransaction::ADJUSTED)
            ->where('notes', 'like', 'Restored:%')->sum('points');
        $owed = -$redeemed - $restored;

        if ($owed <= 0 || $order->customer_id === null) {
            return;
        }

        $balance = $this->lockBalance((int) $order->customer_id);
        $this->write((int) $order->customer_id, $order->id, RewardPointTransaction::ADJUSTED, $owed, $balance + $owed, 'Restored: order '.$order->order_number.' cancelled');
    }

    /**
     * The completion hook (§54.2): once per order, for a customer's
     * non-test order, on its net merchandise amount less the points
     * discount, in the base currency.
     */
    public function earnPoints(Order $order): void
    {
        if ($order->customer_id === null || $order->is_test || $order->order_type !== 'standard' || ! $this->active()
            || RewardPointTransaction::query()->where('order_id', $order->id)->where('type', RewardPointTransaction::EARNED)->exists()) {
            return;
        }

        $settings = $this->getSettings();
        // Net merchandise amount (§38.5): Σ (line_total − tax_amount), whatever the tax mode.
        $net = OrderItem::query()->where('order_id', $order->id)->get(['line_total', 'tax_amount'])
            ->reduce(static fn (string $sum, OrderItem $i): string => Money::add($sum, Money::sub((string) $i->line_total, (string) $i->tax_amount)), Money::normalize(0));
        $eligible = Money::mul(Money::sub($net, (string) $order->reward_points_discount_amount), (string) ($order->exchange_rate_used ?? '1'));

        if (! Money::isPositive($eligible) || ($settings->minimum_order_amount_to_earn !== null && Money::cmp($eligible, (string) $settings->minimum_order_amount_to_earn) < 0)) {
            return;
        }

        $points = (int) bcdiv($eligible, (string) $settings->amount_per_point, 0);

        if ($points < 1) {
            return;
        }

        $balance = $this->lockBalance((int) $order->customer_id);
        $this->write((int) $order->customer_id, $order->id, RewardPointTransaction::EARNED, $points, $balance + $points, 'Earned on order '.$order->order_number,
            $settings->point_expiry_days === null ? null : now()->addDays($settings->point_expiry_days));
    }

    /**
     * A manual adjustment by staff; never below zero.
     */
    public function adjustPoints(Customer $customer, int $points, string $reason): int
    {
        Validator::make(['points' => $points, 'reason' => $reason], [
            'points' => ['required', 'integer', 'not_in:0', 'between:-10000000,10000000'],
            'reason' => ['required', 'string', 'max:255'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($customer, $points, $reason): int {
            $balance = $this->lockBalance($customer->id);

            if ($balance + $points < 0) {
                throw ApiException::unprocessable('insufficient_points', 'The customer has only '.$balance.' points.');
            }

            $this->write($customer->id, null, RewardPointTransaction::ADJUSTED, $points, $balance + $points, $reason);

            return $balance + $points;
        });
    }

    /**
     * ExpireRewardPoints (A-38): each earned row past expires_at, once,
     * expires min(its points, the current balance).
     */
    public function expirePoints(): int
    {
        $expired = 0;

        RewardPointTransaction::query()->where('type', RewardPointTransaction::EARNED)->where('expiry_processed', false)
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->orderBy('id')
            ->chunkById(200, function ($rows) use (&$expired): void {
                foreach ($rows as $row) {
                    DB::connection('tenant')->transaction(function () use ($row, &$expired): void {
                        $balance = $this->lockBalance($row->customer_id);
                        $points = min($row->points, $balance);

                        if ($points > 0) {
                            $this->write($row->customer_id, null, RewardPointTransaction::EXPIRED, -$points, $balance - $points, 'Expired (earned '.$row->created_at?->toDateString().')');
                            $expired += $points;
                        }

                        RewardPointTransaction::query()->whereKey($row->id)->update(['expiry_processed' => true]);
                    });
                }
            });

        return $expired;
    }

    /**
     * @return LengthAwarePaginator<int, RewardPointTransaction>
     */
    public function getTransactionHistory(Customer $customer, int $perPage = 25): LengthAwarePaginator
    {
        return RewardPointTransaction::query()->where('customer_id', $customer->id)->orderByDesc('id')->paginate($perPage);
    }

    /**
     * The customer's balance row, created at 0, locked.
     */
    private function lockBalance(int $customerId): int
    {
        DB::connection('tenant')->table('customer_reward_points')->insertOrIgnore(['customer_id' => $customerId, 'points_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::connection('tenant')->table('customer_reward_points')->where('customer_id', $customerId)->lockForUpdate()->value('points_balance');
    }

    private function write(int $customerId, ?int $orderId, string $type, int $points, int $balanceAfter, string $notes, mixed $expiresAt = null): void
    {
        DB::connection('tenant')->table('customer_reward_points')->where('customer_id', $customerId)->update(['points_balance' => $balanceAfter, 'updated_at' => now()]);

        $row = new RewardPointTransaction;
        $row->forceFill([
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'type' => $type,
            'points' => $points,
            'balance_after' => $balanceAfter,
            'expires_at' => $expiresAt,
            'notes' => mb_substr($notes, 0, 255),
        ])->save();
    }
}
