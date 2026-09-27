<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use RuntimeException;

/**
 * A posting that cannot be made as asked (no open period, an unbalanced
 * entry). The outbox request is marked failed with this message.
 */
final class PostingException extends RuntimeException {}
