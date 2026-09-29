<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

/**
 * A storefront guest identified by X-Guest-Token, for private-channel
 * authorisation of their support conversations (spec §59.2). It is not a
 * user and never authenticates anything else.
 */
final readonly class GuestSupportActor
{
    public function __construct(public string $guestToken) {}

    /**
     * Broadcasting keys presence members by this identifier.
     */
    public function getAuthIdentifier(): string
    {
        return 'guest:'.substr(hash('sha256', $this->guestToken), 0, 16);
    }
}
