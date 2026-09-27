<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A fiscal period (spec §57.3), normally a month; entries close against it.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status open | closed
 * @property Carbon|null $closed_at
 * @property int|null $closed_by_user_id
 * @property-read FiscalYear $year
 */
class FiscalPeriod extends Model
{
    public const string OPEN = 'open';

    public const string CLOSED = 'closed';

    protected $connection = 'tenant';

    protected $fillable = ['name', 'starts_on', 'ends_on'];

    protected $casts = ['fiscal_year_id' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime', 'closed_by_user_id' => 'integer'];

    /**
     * @return BelongsTo<FiscalYear, $this>
     */
    public function year(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }
}
