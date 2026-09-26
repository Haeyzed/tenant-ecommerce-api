<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Support;

use Closure;

/**
 * Whether a buyer has placed an order before, for first_order_only
 * promotions (spec §37.2). The Orders module registers the resolver;
 * without it, every identified buyer's next order is their first.
 */
final class BuyerHistory
{
    /** @var (Closure(?int, ?string, ?int): bool)|null */
    private ?Closure $resolver = null;

    /**
     * @param  Closure(?int $customerId, ?string $email, ?int $excludeOrderId): bool  $hasOrdered
     */
    public function useResolver(Closure $hasOrdered): void
    {
        $this->resolver = $hasOrdered;
    }

    /**
     * @param  int|null  $excludeOrderId  the order being placed, which does not count
     */
    public function isFirstOrder(?int $customerId, ?string $email, ?int $excludeOrderId = null): bool
    {
        if ($customerId === null && blank($email)) {
            return false;
        }

        return $this->resolver === null || ! ($this->resolver)($customerId, $email === null ? null : strtolower($email), $excludeOrderId);
    }
}
