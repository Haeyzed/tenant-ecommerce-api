<?php

declare(strict_types=1);

namespace App\Modules\Customers\Events;

use App\Modules\Customers\Models\Customer;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer registered or signed in, possibly with a guest token (spec
 * §38.2). The Cart module listens to merge the guest cart; nothing else
 * depends on it.
 */
final class CustomerAuthenticated
{
    use Dispatchable;

    public function __construct(
        public readonly Customer $customer,
        public readonly ?string $guestToken,
        public readonly bool $registered,
    ) {}
}
