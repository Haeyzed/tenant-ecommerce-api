<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One unique visitor-day on an affiliate's link (spec §21A.3). The IP is
 * stored only as a keyed hash.
 *
 * @property int $id
 * @property int $affiliate_id
 * @property string $visitor_id
 * @property string|null $landing_path
 * @property string|null $referrer_host
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string $ip_hash
 * @property string|null $user_agent
 * @property Carbon $created_at
 * @property-read Affiliate $affiliate
 */
class AffiliateClick extends Model
{
    public const null UPDATED_AT = null;

    protected $connection = 'landlord';

    protected $fillable = ['affiliate_id', 'visitor_id', 'landing_path', 'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'ip_hash', 'user_agent'];

    protected $hidden = ['ip_hash'];

    /**
     * @return BelongsTo<Affiliate, $this>
     */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
