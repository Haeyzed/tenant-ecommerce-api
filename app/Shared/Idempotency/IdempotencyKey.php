<?php

declare(strict_types=1);

namespace App\Shared\Idempotency;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One idempotent request (spec §70.11). The table exists in the landlord
 * database and in every tenant database; the model uses the connection of
 * the current context.
 *
 * @property int $id
 * @property string $scope
 * @property string $route
 * @property string $key
 * @property string $request_hash
 * @property string $status
 * @property int|null $response_status
 * @property string|null $response_body
 * @property Carbon $locked_until
 * @property Carbon $expires_at
 */
final class IdempotencyKey extends Model
{
    public const string PROCESSING = 'processing';

    public const string COMPLETED = 'completed';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'response_body' => 'encrypted',
        'locked_until' => 'datetime',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
