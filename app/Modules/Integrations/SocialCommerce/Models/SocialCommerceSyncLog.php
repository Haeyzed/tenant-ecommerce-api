<?php

declare(strict_types=1);

namespace App\Modules\Integrations\SocialCommerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $social_commerce_account_id
 * @property string $sync_type product | order
 * @property string $trigger scheduled | manual
 * @property string $status success | failed | partial
 * @property int $items_processed
 * @property int $items_failed
 * @property list<array<string, mixed>>|null $error_details
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 */
class SocialCommerceSyncLog extends Model
{
    public const array TYPES = ['product', 'order'];

    public const array STATUSES = ['success', 'failed', 'partial'];

    protected $table = 'social_commerce_sync_logs';

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = [
        'social_commerce_account_id' => 'integer',
        'items_processed' => 'integer',
        'items_failed' => 'integer',
        'error_details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
