<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Models\Order;
use Closure;

/**
 * Extension points of the order lifecycle for the modules that settle
 * against orders (gift cards §46, installments §47, reward points §54).
 * Each hook runs inside the order's own transaction, with the order row
 * locked, so a failure rolls the whole transition back.
 *
 *   confirmed  once, when the sale is committed (§39.4)
 *   cancelled  when the order is cancelled (§39.5)
 *   completed  once, when the order first reaches delivered or completed
 */
final class OrderLifecycle
{
    public const string CONFIRMED = 'confirmed';

    public const string CANCELLED = 'cancelled';

    public const string COMPLETED = 'completed';

    /** @var array<string, array<string, Closure(Order): void>> */
    private array $hooks = [];

    /**
     * @param  Closure(Order $locked): void  $hook
     */
    public function on(string $event, string $name, Closure $hook): void
    {
        $this->hooks[$event][$name] = $hook;
    }

    public function run(string $event, Order $locked): void
    {
        foreach ($this->hooks[$event] ?? [] as $hook) {
            $hook($locked);
        }
    }
}
