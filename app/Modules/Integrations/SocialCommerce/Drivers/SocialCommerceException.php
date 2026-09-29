<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Drivers;

use RuntimeException;

/**
 * A channel call failed. The message never carries the access token; an
 * expired token reads as such, so the log tells the tenant to reconnect
 * (§69.2, UD-23).
 */
final class SocialCommerceException extends RuntimeException {}
