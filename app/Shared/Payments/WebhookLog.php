<?php

declare(strict_types=1);

namespace App\Shared\Payments;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A verified payment webhook, stored in the database of the context it
 * belongs to (spec §15.6): landlord for billing, the tenant's own database
 * for storefront payments. Always queried through on(), so the database is
 * explicit.
 *
 * @property int $id
 * @property string $provider
 * @property string $mode
 * @property string $provider_event_id
 * @property string|null $event_type
 * @property string|null $provider_reference
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property int $attempts
 * @property string|null $error
 */
class WebhookLog extends Model
{
    protected $fillable = ['provider', 'mode', 'provider_event_id', 'event_type', 'provider_reference', 'payload', 'received_at', 'processed_at', 'attempts', 'error'];

    protected $casts = [
        'payload' => 'encrypted:array',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * @return Builder<self>
     */
    public static function landlord(): Builder
    {
        return static::on('landlord');
    }
}
