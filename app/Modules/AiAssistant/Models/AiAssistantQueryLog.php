<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Models;

use App\Modules\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question asked (spec §62.1). Unmatched questions show an admin what
 * the catalogue is missing.
 *
 * @property int $id
 * @property int $user_id
 * @property string $raw_query
 * @property string|null $matched_intent_key
 * @property string|null $response_summary
 * @property Carbon $created_at
 * @property-read User $user
 */
class AiAssistantQueryLog extends Model
{
    public const null UPDATED_AT = null;

    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['user_id' => 'integer'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
