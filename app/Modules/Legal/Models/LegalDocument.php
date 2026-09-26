<?php

declare(strict_types=1);

namespace App\Modules\Legal\Models;

use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * One version of a platform legal document (spec §9.2). Immutable once
 * published: an edit is a new draft with a new version.
 *
 * @property int $id
 * @property string $document_type
 * @property string $version
 * @property string $title
 * @property string $body
 * @property string $status draft | published | retired
 * @property Carbon|null $published_at
 * @property Carbon|null $effective_at
 * @property bool $required_at_registration
 * @property bool $requires_reacceptance
 */
class LegalDocument extends Model implements Auditable
{
    use AuditsToLandlord;

    public const array TYPES = ['terms_of_service', 'privacy_policy', 'data_processing_agreement', 'acceptable_use_policy', 'affiliate_agreement'];

    public const string DRAFT = 'draft';

    public const string PUBLISHED = 'published';

    public const string RETIRED = 'retired';

    protected $connection = 'landlord';

    protected $fillable = ['document_type', 'version', 'title', 'body', 'status', 'published_at', 'effective_at', 'required_at_registration', 'requires_reacceptance'];

    protected $casts = [
        'published_at' => 'datetime',
        'effective_at' => 'datetime',
        'required_at_registration' => 'boolean',
        'requires_reacceptance' => 'boolean',
    ];

    /**
     * @return HasMany<LegalAcceptance, $this>
     */
    public function acceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }
}
