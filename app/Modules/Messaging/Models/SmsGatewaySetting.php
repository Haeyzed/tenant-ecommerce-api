<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A tenant's SMS provider configuration (spec §16.3).
 *
 * @property int $id
 * @property string $provider termii | africas_talking
 * @property array<string, string> $credentials
 * @property bool $is_active
 * @property bool $is_default
 */
class SmsGatewaySetting extends Model implements AuditableContract
{
    use Auditable;

    public const array PROVIDERS = ['termii', 'africas_talking'];

    protected $connection = 'tenant';

    protected $fillable = ['provider', 'credentials', 'is_active', 'is_default'];

    protected $hidden = ['credentials'];

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['credentials'];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Credentials with every value masked except the last four characters.
     *
     * @return array<string, string>
     */
    public function maskedCredentials(): array
    {
        return array_map(
            static fn (mixed $value): string => str_repeat('*', max(0, mb_strlen((string) $value) - 4)).mb_substr((string) $value, -4),
            $this->credentials,
        );
    }
}
