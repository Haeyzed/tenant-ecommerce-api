<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A posted journal entry (spec §57.3). Immutable once created: the only
 * later change is reversed_at, set once when a reversal posts.
 *
 * @property int $id
 * @property Carbon $entry_date
 * @property int $fiscal_period_id
 * @property string $description
 * @property string $source system | manual | reversal
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $posting_key
 * @property string $cash_flow_category operating | investing | financing
 * @property int|null $reverses_journal_entry_id
 * @property Carbon|null $reversed_at
 * @property int|null $created_by_user_id
 * @property-read \Illuminate\Database\Eloquent\Collection<int, JournalEntryLine> $lines
 * @property-read FiscalPeriod $period
 */
class JournalEntry extends Model implements AuditableContract
{
    use Auditable;

    public const string SYSTEM = 'system';

    public const string MANUAL = 'manual';

    public const string REVERSAL = 'reversal';

    public const array CASH_FLOW_CATEGORIES = ['operating', 'investing', 'financing'];

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'entry_date' => 'date',
        'fiscal_period_id' => 'integer',
        'reference_id' => 'integer',
        'reverses_journal_entry_id' => 'integer',
        'reversed_at' => 'datetime',
        'created_by_user_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $entry): void {
            $changed = array_keys($entry->getDirty());

            if (array_diff($changed, ['reversed_at', 'updated_at']) !== [] || $entry->getOriginal('reversed_at') !== null) {
                throw new LogicException('Journal entries are immutable; post a reversal instead.');
            }
        });
        static::deleting(static fn (): never => throw new LogicException('Journal entries are immutable; post a reversal instead.'));
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id')->withTrashed();
    }
}
