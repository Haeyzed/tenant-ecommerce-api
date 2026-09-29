<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Support;

use RuntimeException;

/**
 * A WooCommerce call failed (unreachable, rejected credentials, an error
 * response). The message is safe to log and show: it never carries the
 * credentials.
 */
final class WooCommerceException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
