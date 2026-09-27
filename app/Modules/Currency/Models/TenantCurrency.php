<?php

declare(strict_types=1);

namespace App\Modules\Currency\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A currency the store sells in (spec §48.1). Exactly one row is the base
 * (a unique generated column enforces it); it mirrors
 * tenant_settings.default_currency.
 *
 * @property int $id
 * @property string $currency_code
 * @property bool $is_base
 * @property bool $is_active
 * @property string|null $display_symbol
 */
class TenantCurrency extends Model implements AuditableContract
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['currency_code', 'display_symbol'];

    protected $casts = ['is_base' => 'boolean', 'is_active' => 'boolean'];

    /**
     * The generated column is never written.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['base_marker'];
}
