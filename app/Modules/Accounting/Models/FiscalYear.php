<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A fiscal year (spec §57.3): the roll-up of its periods.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status open | closed
 * @property Carbon|null $closed_at
 */
class FiscalYear extends Model
{
    public const string OPEN = 'open';

    public const string CLOSED = 'closed';

    protected $connection = 'tenant';

    protected $fillable = ['name', 'starts_on', 'ends_on'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'closed_at' => 'datetime'];

    /**
     * @return HasMany<FiscalPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(FiscalPeriod::class);
    }
}
