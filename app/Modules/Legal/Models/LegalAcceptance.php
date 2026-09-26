<?php

declare(strict_types=1);

namespace App\Modules\Legal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An immutable record that someone accepted one document version (§9.2).
 *
 * @property int $id
 * @property int $legal_document_id
 * @property int|null $tenant_registration_id
 * @property string|null $tenant_id
 * @property int|null $affiliate_id
 * @property int|null $accepted_by_user_id
 * @property string $accepted_by_name
 * @property string $accepted_by_email
 * @property string $context registration | reacceptance | affiliate_application
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $accepted_at
 */
class LegalAcceptance extends Model
{
    public const null UPDATED_AT = null;

    protected $connection = 'landlord';

    protected $fillable = [
        'legal_document_id', 'tenant_registration_id', 'tenant_id', 'affiliate_id', 'accepted_by_user_id', 'accepted_by_name',
        'accepted_by_email', 'context', 'ip_address', 'user_agent', 'accepted_at',
    ];

    protected $casts = ['accepted_at' => 'datetime'];

    /**
     * @return BelongsTo<LegalDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }
}
