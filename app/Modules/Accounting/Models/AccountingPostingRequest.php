<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One request in the accounting outbox (spec §57.3, A-43): written inside
 * the business transaction, posted after commit by PostAccountingEntry.
 *
 * @property int $id
 * @property string $posting_key
 * @property string $method
 * @property string $reference_type
 * @property int $reference_id
 * @property Carbon $event_date
 * @property array<string, mixed>|null $payload
 * @property string $status pending | posted | skipped | failed
 * @property int $attempts
 * @property string|null $last_error
 * @property int|null $journal_entry_id
 * @property Carbon|null $processed_at
 */
class AccountingPostingRequest extends Model
{
    public const string PENDING = 'pending';

    public const string POSTED = 'posted';

    public const string SKIPPED = 'skipped';

    public const string FAILED = 'failed';

    public const array STATUSES = [self::PENDING, self::POSTED, self::SKIPPED, self::FAILED];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'reference_id' => 'integer',
        'event_date' => 'date',
        'payload' => 'array',
        'attempts' => 'integer',
        'journal_entry_id' => 'integer',
        'processed_at' => 'datetime',
    ];
}
