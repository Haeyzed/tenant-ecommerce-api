<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One sync run (spec §68.2): success, partial (some items failed) or
 * failed (the run itself failed, e.g. bad credentials).
 *
 * @property int $id
 * @property string $sync_type product | category | tax_rate | order | stock
 * @property string $direction pull | push
 * @property string $trigger scheduled | manual
 * @property string $status success | failed | partial
 * @property int $items_processed
 * @property int $items_failed
 * @property list<array<string, mixed>>|null $error_details
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 */
class WooCommerceSyncLog extends Model
{
    public const array TYPES = ['product', 'category', 'tax_rate', 'order', 'stock'];

    public const array STATUSES = ['success', 'failed', 'partial'];

    protected $table = 'woocommerce_sync_logs';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'items_processed' => 'integer',
        'items_failed' => 'integer',
        'error_details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
