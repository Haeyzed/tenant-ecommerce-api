<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One debit or credit of a journal entry (spec §57.3). This table is the
 * general ledger; lines are immutable.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property string $type debit | credit
 * @property string $amount always positive
 * @property string|null $description
 * @property-read Account $account
 * @property-read JournalEntry $entry
 */
class JournalEntryLine extends Model
{
    public const string DEBIT = 'debit';

    public const string CREDIT = 'credit';

    public const null UPDATED_AT = null;

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['journal_entry_id' => 'integer', 'account_id' => 'integer', 'amount' => 'decimal:4'];

    protected static function booted(): void
    {
        static::updating(static fn (): never => throw new LogicException('Journal entry lines are immutable.'));
        static::deleting(static fn (): never => throw new LogicException('Journal entry lines are immutable.'));
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
