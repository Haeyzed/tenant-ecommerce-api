<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A tenant's usage and activity for one day, reported from inside its own
 * database by TenantUsageReporter (spec §22.6). Cross-tenant figures read
 * only these rows, never tenant databases.
 *
 * @property int $id
 * @property string $tenant_id
 * @property Carbon $date
 * @property array<string, int> $usage
 * @property int $orders_count
 * @property string $gross_sales
 * @property string $base_currency
 * @property int $webhook_failures
 * @property int $failed_postings
 */
class TenantUsageSnapshot extends Model
{
    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'date', 'usage', 'orders_count', 'gross_sales', 'base_currency', 'webhook_failures', 'failed_postings'];

    protected $casts = [
        'date' => 'date',
        'usage' => 'array',
        'orders_count' => 'integer',
        'gross_sales' => 'decimal:4',
        'webhook_failures' => 'integer',
        'failed_postings' => 'integer',
    ];
}
