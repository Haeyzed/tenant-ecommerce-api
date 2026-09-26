<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An FCM registration token of a tenant actor's device (spec §16.5).
 *
 * @property int $id
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property string $token
 * @property string $platform ios | android | web
 * @property Carbon|null $last_used_at
 */
class PushDeviceToken extends Model
{
    public const array PLATFORMS = ['ios', 'android', 'web'];

    public const int MAX_PER_ACTOR = 10;

    protected $connection = 'tenant';

    protected $fillable = ['tokenable_type', 'tokenable_id', 'token', 'platform', 'last_used_at'];

    protected $hidden = ['token'];

    protected $casts = ['last_used_at' => 'datetime'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }
}
