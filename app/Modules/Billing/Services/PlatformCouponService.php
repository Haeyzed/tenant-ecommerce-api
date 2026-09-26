<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Models\PlatformCoupon;
use App\Modules\Billing\Models\PlatformCouponRedemption;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform subscription coupons (spec §14.8): validation, reservation under
 * a coupon row lock, per-cycle application and release.
 */
final readonly class PlatformCouponService
{
    public const array IMMUTABLE_ONCE_REDEEMED = ['discount_type', 'discount_value', 'currency_code', 'duration'];

    public function __construct(private PlatformSettingsService $settings) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, PlatformUser $by): PlatformCoupon
    {
        $validated = $this->validateDefinition($data);

        return DB::connection('landlord')->transaction(function () use ($validated, $by): PlatformCoupon {
            /** @var PlatformCoupon $coupon */
            $coupon = PlatformCoupon::query()->create(array_diff_key($validated, ['targets' => true]) + ['created_by' => $by->id]);

            $this->syncTargets($coupon, $validated['targets'] ?? []);
            ActivityRecorder::landlord('platform_coupons', "Coupon {$coupon->code} created", $coupon, [], $by);

            return $coupon->load('targets');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PlatformCoupon $coupon, array $data, PlatformUser $by): PlatformCoupon
    {
        $validated = $this->validateDefinition($data + $coupon->only(['code', 'name', 'discount_type', 'discount_value', 'duration']), $coupon);
        $changes = array_intersect_key($validated, $data);

        if ($coupon->redemptions()->exists()) {
            foreach (self::IMMUTABLE_ONCE_REDEEMED as $field) {
                if (array_key_exists($field, $changes) && (string) $changes[$field] !== (string) $coupon->getAttribute($field)) {
                    throw ApiException::unprocessable('coupon_redeemed', "{$field} cannot change once the coupon has been redeemed.", ['field' => $field]);
                }
            }
        }

        return DB::connection('landlord')->transaction(function () use ($coupon, $changes, $by): PlatformCoupon {
            $coupon->fill(array_diff_key($changes, ['targets' => true, 'code' => true]))->save();

            if (array_key_exists('targets', $changes)) {
                $this->syncTargets($coupon, $changes['targets']);
            }

            ActivityRecorder::landlord('platform_coupons', "Coupon {$coupon->code} updated", $coupon, [], $by);

            return $coupon->load('targets');
        });
    }

    public function deactivate(PlatformCoupon $coupon, PlatformUser $by): PlatformCoupon
    {
        $coupon->forceFill(['is_active' => false])->save();
        ActivityRecorder::landlord('platform_coupons', "Coupon {$coupon->code} deactivated", $coupon, [], $by);

        return $coupon;
    }

    /**
     * @param  list<array{target_type: string, target_id: int}>  $targets
     */
    public function syncTargets(PlatformCoupon $coupon, array $targets): void
    {
        $coupon->targets()->delete();

        foreach ($targets as $target) {
            $coupon->targets()->create(['target_type' => $target['target_type'], 'target_id' => (int) $target['target_id']]);
        }
    }

    /**
     * @return array{valid: bool, reason: string|null, coupon: PlatformCoupon|null, discount: string|null, currency_code: string}
     */
    public function validate(string $code, PlanPrice $price, ?Tenant $tenant, string $ownerEmail): array
    {
        $coupon = PlatformCoupon::query()->with('targets')->where('code', strtoupper(trim($code)))->first();
        $reason = $coupon === null ? 'not_found' : $this->rejection($coupon, $price, $tenant, strtolower($ownerEmail));

        return [
            'valid' => $reason === null,
            'reason' => $reason,
            'coupon' => $reason === null ? $coupon : null,
            'discount' => $reason === null && $coupon !== null
                ? self::discount($coupon->discount_type, (string) $coupon->discount_value, $coupon->max_discount_amount, (string) $price->amount, $price->currency_code)
                : null,
            'currency_code' => $price->currency_code,
        ];
    }

    /**
     * Validation at registration: no tenant exists yet (§9.3).
     *
     * @return array{valid: bool, reason: string|null, coupon: PlatformCoupon|null, discount: string|null, currency_code: string}
     */
    public function validateForRegistration(string $code, PlanPrice $price, string $ownerEmail): array
    {
        return $this->validate($code, $price, null, $ownerEmail);
    }

    /**
     * Reserves the coupon for a subscription under a lock on the coupon
     * row, so usage_limit_total can never be exceeded.
     */
    public function reserve(PlatformCoupon $coupon, Subscription $subscription, string $ownerEmail): PlatformCouponRedemption
    {
        return DB::connection('landlord')->transaction(function () use ($coupon, $subscription, $ownerEmail): PlatformCouponRedemption {
            /** @var PlatformCoupon $locked */
            $locked = PlatformCoupon::query()->with('targets')->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            $subscription->loadMissing(['planPrice', 'tenant']);

            $reason = $this->rejection($locked, $subscription->planPrice, $subscription->tenant, strtolower($ownerEmail), $subscription->id);

            if ($reason !== null) {
                throw ApiException::unprocessable('coupon_invalid', 'This coupon cannot be applied.', ['reason' => $reason]);
            }

            /** @var PlatformCouponRedemption $redemption */
            $redemption = PlatformCouponRedemption::query()->create([
                'platform_coupon_id' => $locked->id,
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'owner_email' => strtolower($ownerEmail),
                'code_snapshot' => $locked->code,
                'discount_type_snapshot' => $locked->discount_type,
                'discount_value_snapshot' => $locked->discount_value,
                'max_discount_snapshot' => $locked->max_discount_amount,
                'cycles_total' => $locked->cycles(),
                'status' => PlatformCouponRedemption::RESERVED,
                'reserved_at' => now(),
            ]);

            $locked->increment('times_redeemed');

            return $redemption;
        });
    }

    /**
     * The open redemption of a subscription, if any.
     */
    public function openRedemption(Subscription $subscription): ?PlatformCouponRedemption
    {
        return PlatformCouponRedemption::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('status', [PlatformCouponRedemption::RESERVED, PlatformCouponRedemption::ACTIVE])
            ->first();
    }

    /**
     * The discount the redemption gives on a plan line, from its snapshot.
     */
    public function discountFor(PlatformCouponRedemption $redemption, string $planAmount, string $currency): string
    {
        return self::discount(
            $redemption->discount_type_snapshot,
            (string) $redemption->discount_value_snapshot,
            $redemption->max_discount_snapshot,
            $planAmount,
            $currency,
        );
    }

    /**
     * Records one applied cycle from a successful charge's coupon line.
     * Must run inside the charge's transition transaction.
     */
    public function applyCycle(PlatformCouponRedemption $redemption, PaymentTransaction $charge): string
    {
        $discount = '0';

        foreach ((array) $charge->line_items as $line) {
            if (($line['type'] ?? null) === 'coupon_discount') {
                $discount = Money::add($discount, Money::normalize(ltrim((string) $line['amount'], '-')));
            }
        }

        if (! Money::isPositive($discount) || ! $redemption->isOpen()) {
            return '0.0000';
        }

        $cycles = $redemption->cycles_applied + 1;

        $redemption->forceFill([
            'cycles_applied' => $cycles,
            'total_discount_amount' => Money::add((string) $redemption->total_discount_amount, $discount),
            'status' => $cycles >= $redemption->cycles_total ? PlatformCouponRedemption::COMPLETED : PlatformCouponRedemption::ACTIVE,
            'activated_at' => $redemption->activated_at ?? now(),
            'completed_at' => $cycles >= $redemption->cycles_total ? now() : null,
        ])->save();

        return Money::normalize($discount);
    }

    /**
     * Ends a redemption early (plan change to a price that no longer
     * qualifies).
     */
    public function complete(PlatformCouponRedemption $redemption): void
    {
        if ($redemption->isOpen()) {
            $redemption->forceFill(['status' => PlatformCouponRedemption::COMPLETED, 'completed_at' => now()])->save();
        }
    }

    /**
     * Releases a reservation that never saw a successful charge and frees
     * its use of the coupon.
     */
    public function release(PlatformCouponRedemption $redemption): void
    {
        DB::connection('landlord')->transaction(function () use ($redemption): void {
            /** @var PlatformCouponRedemption $locked */
            $locked = PlatformCouponRedemption::query()->whereKey($redemption->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PlatformCouponRedemption::RESERVED) {
                return;
            }

            PlatformCoupon::query()->whereKey($locked->platform_coupon_id)->lockForUpdate()->first();
            $locked->forceFill(['status' => PlatformCouponRedemption::RELEASED, 'released_at' => now()])->save();
            PlatformCoupon::query()->whereKey($locked->platform_coupon_id)->where('times_redeemed', '>', 0)->decrement('times_redeemed');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function usageStats(PlatformCoupon $coupon): array
    {
        $rows = PlatformCouponRedemption::query()
            ->where('platform_coupon_id', $coupon->id)
            ->selectRaw('status, count(*) as total, coalesce(sum(total_discount_amount), 0) as discount')
            ->groupBy('status')
            ->get();

        return [
            'times_redeemed' => $coupon->times_redeemed,
            'remaining' => $coupon->usage_limit_total === null ? null : max(0, $coupon->usage_limit_total - $coupon->times_redeemed),
            'by_status' => $rows->mapWithKeys(static fn ($row): array => [$row->status => (int) $row->total])->all(),
            'total_discount_amount' => $rows->reduce(static fn (string $sum, $row): string => Money::add($sum, Money::normalize((string) $row->discount)), '0.0000'),
        ];
    }

    public static function discount(string $type, string $value, ?string $max, string $planAmount, string $currency): string
    {
        $discount = $type === PlatformCoupon::PERCENTAGE
            ? Money::div(Money::mul($planAmount, $value), '100')
            : $value;

        if ($type === PlatformCoupon::PERCENTAGE && $max !== null) {
            $discount = Money::min($discount, $max);
        }

        return Money::round(Money::min($discount, $planAmount), $currency);
    }

    private function rejection(PlatformCoupon $coupon, PlanPrice $price, ?Tenant $tenant, string $ownerEmail, ?int $ignoreSubscriptionId = null): ?string
    {
        $now = now();

        return match (true) {
            ! $coupon->is_active => 'inactive',
            $coupon->starts_at !== null && $coupon->starts_at->isAfter($now) => 'not_started',
            $coupon->ends_at !== null && $coupon->ends_at->isBefore($now) => 'expired',
            ! $this->targetsPrice($coupon, $price) => 'not_applicable_to_price',
            $coupon->currency_code !== null && $coupon->currency_code !== $price->currency_code => 'currency_mismatch',
            ! $this->meetsMinimum($coupon, $price) => 'minimum_not_met',
            $coupon->first_subscription_only && $tenant !== null && Subscription::query()
                ->where('tenant_id', $tenant->getTenantKey())
                ->when($ignoreSubscriptionId !== null, static fn ($q) => $q->where('id', '!=', $ignoreSubscriptionId))
                ->exists() => 'first_subscription_only',
            $coupon->usage_limit_total !== null && $coupon->times_redeemed >= $coupon->usage_limit_total => 'usage_limit_reached',
            $this->tenantUses($coupon, $tenant, $ownerEmail) >= $coupon->usage_limit_per_tenant => 'tenant_limit_reached',
            default => null,
        };
    }

    private function targetsPrice(PlatformCoupon $coupon, PlanPrice $price): bool
    {
        if ($coupon->targets->isEmpty()) {
            return true;
        }

        return $coupon->targets->contains(static fn ($target): bool => ($target->target_type === 'plan_price' && $target->target_id === $price->id)
            || ($target->target_type === 'plan' && $target->target_id === $price->plan_id));
    }

    private function meetsMinimum(PlatformCoupon $coupon, PlanPrice $price): bool
    {
        if ($coupon->min_amount === null) {
            return true;
        }

        // A percentage coupon without a currency applies its minimum only
        // to prices in the platform default currency.
        if ($coupon->currency_code === null && $price->currency_code !== (string) $this->settings->get('default_currency', 'USD')) {
            return true;
        }

        return Money::cmp((string) $price->amount, (string) $coupon->min_amount) >= 0;
    }

    private function tenantUses(PlatformCoupon $coupon, ?Tenant $tenant, string $ownerEmail): int
    {
        return PlatformCouponRedemption::query()
            ->where('platform_coupon_id', $coupon->id)
            ->where('status', '!=', PlatformCouponRedemption::RELEASED)
            ->where(static fn ($q) => $q->where('owner_email', $ownerEmail)
                ->when($tenant !== null, static fn ($q) => $q->orWhere('tenant_id', $tenant->getTenantKey())))
            ->count();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateDefinition(array $data, ?PlatformCoupon $existing = null): array
    {
        if (isset($data['code'])) {
            $data['code'] = strtoupper(trim((string) $data['code']));
        }

        if (isset($data['currency_code'])) {
            $data['currency_code'] = strtoupper((string) $data['currency_code']);
        }

        return validator($data, [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{4,32}$/', Rule::unique('landlord.platform_coupons', 'code')->ignore($existing?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in([PlatformCoupon::PERCENTAGE, PlatformCoupon::FIXED_AMOUNT])],
            'discount_value' => ['required', 'numeric', 'gt:0', Rule::when(($data['discount_type'] ?? null) === PlatformCoupon::PERCENTAGE, ['lte:100'])],
            'currency_code' => [Rule::requiredIf(($data['discount_type'] ?? null) === PlatformCoupon::FIXED_AMOUNT), 'nullable', 'string', 'size:3'],
            'duration' => ['required', Rule::in(['once', 'repeating'])],
            'duration_cycles' => [Rule::requiredIf(($data['duration'] ?? null) === 'repeating'), 'nullable', 'integer', 'min:2', 'max:36'],
            'max_discount_amount' => ['nullable', 'numeric', 'gt:0'],
            'min_amount' => ['nullable', 'numeric', 'gte:0'],
            'first_subscription_only' => ['sometimes', 'boolean'],
            'usage_limit_total' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_tenant' => ['sometimes', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'affiliate_id' => ['nullable', 'integer', Rule::exists('landlord.affiliates', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'targets' => ['sometimes', 'array'],
            'targets.*.target_type' => ['required', Rule::in(['plan', 'plan_price'])],
            'targets.*.target_id' => ['required', 'integer'],
        ])->validate();
    }
}
