<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Support;

use Carbon\CarbonInterface;

/**
 * Everything the discount engine reads (spec §37.6). exchangeRate is basket
 * currency per unit of base currency (1 when the basket is in the base
 * currency). A guest has no customer id; its group defaults to the store's
 * default customer group.
 */
final readonly class PricingContext
{
    public const string ONLINE = 'online';

    public const string POS = 'pos';

    /**
     * @param  list<PricingLine>  $lines
     */
    public function __construct(
        public array $lines,
        public string $currency,
        public ?int $customerId = null,
        public ?int $customerGroupId = null,
        public ?string $email = null,
        public bool $isFirstOrder = false,
        public string $channel = self::ONLINE,
        public string $exchangeRate = '1',
        public ?string $couponCode = null,
        public ?string $shippingAmount = null,
        public ?CarbonInterface $at = null,
    ) {}
}
