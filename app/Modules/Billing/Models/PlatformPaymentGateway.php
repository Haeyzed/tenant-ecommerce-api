<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Shared\Auditing\AuditsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * The platform's own credentials for one provider and mode (spec §15.10).
 * Secrets are write-only: hidden from serialisation and excluded from audits.
 *
 * @property int $id
 * @property string $provider
 * @property string $mode
 * @property string|null $public_key
 * @property string $secret_key
 * @property string|null $webhook_secret
 * @property array<string, string>|null $extra_credentials
 * @property bool $is_enabled
 * @property bool $is_default
 * @property int $sort_order
 * @property list<string> $supported_currencies
 * @property list<int>|null $supported_country_ids
 * @property Carbon|null $credentials_verified_at
 * @property Carbon|null $last_webhook_at
 * @property int|null $updated_by
 */
class PlatformPaymentGateway extends Model implements Auditable
{
    use AuditsToLandlord;

    public const array PROVIDERS = ['flutterwave', 'paystack', 'stripe'];

    protected $connection = 'landlord';

    protected $fillable = [
        'provider', 'mode', 'public_key', 'secret_key', 'webhook_secret', 'extra_credentials', 'is_enabled', 'is_default',
        'sort_order', 'supported_currencies', 'supported_country_ids', 'credentials_verified_at', 'last_webhook_at', 'updated_by',
    ];

    protected $hidden = ['secret_key', 'webhook_secret', 'extra_credentials'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['secret_key', 'webhook_secret', 'extra_credentials', 'last_webhook_at'];

    protected $casts = [
        'secret_key' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'extra_credentials' => 'encrypted:array',
        'is_enabled' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
        'supported_currencies' => 'array',
        'supported_country_ids' => 'array',
        'credentials_verified_at' => 'datetime',
        'last_webhook_at' => 'datetime',
    ];

    public function maskedPublicKey(): ?string
    {
        return $this->public_key === null ? null : '…'.mb_substr($this->public_key, -4);
    }
}
