<?php

declare(strict_types=1);

namespace App\Modules\Currency\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A reference rate (spec §48.1): 1 base = rate target. Used to estimate
 * prices without an explicit product price and to convert baskets to the
 * base currency. A manual rate is never overwritten by the provider.
 *
 * @property int $id
 * @property string $base_currency_code
 * @property string $target_currency_code
 * @property string $rate
 * @property string $source manual | provider
 * @property Carbon $fetched_at
 */
class ExchangeRate extends Model
{
    public const string MANUAL = 'manual';

    public const string PROVIDER = 'provider';

    protected $connection = 'tenant';

    protected $table = 'currency_exchange_rates';

    protected $fillable = [];

    protected $casts = ['rate' => 'decimal:12', 'fetched_at' => 'datetime'];
}
