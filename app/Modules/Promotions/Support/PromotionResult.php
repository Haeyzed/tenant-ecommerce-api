<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Support;

use App\Shared\Support\Money;

/**
 * The engine's output (spec §37.6), in the basket currency. Line amounts
 * are keyed by line position.
 */
final readonly class PromotionResult
{
    /**
     * @param  array<int, array{discount_amount: string, seller_funded_discount_amount: string}>  $lines
     * @param  list<array{promotion_id: int, coupon_id: int|null, scope: string, discount_type: string, label: string, amount: string, seller_id: int|null}>  $applied
     */
    public function __construct(
        public array $lines,
        public string $orderDiscountAmount,
        public string $shippingDiscountAmount,
        public array $applied,
        public ?int $couponId = null,
        public ?string $couponRejectionReason = null,
    ) {}

    public function lineDiscount(int $position): string
    {
        return $this->lines[$position]['discount_amount'] ?? Money::normalize(0);
    }

    /**
     * Σ line discounts (line promotions plus allocated order promotion).
     */
    public function discountAmount(): string
    {
        return array_reduce($this->lines, static fn (string $sum, array $line): string => Money::add($sum, $line['discount_amount']), Money::normalize(0));
    }

    public function couponApplied(): bool
    {
        return $this->couponId !== null && $this->couponRejectionReason === null;
    }
}
