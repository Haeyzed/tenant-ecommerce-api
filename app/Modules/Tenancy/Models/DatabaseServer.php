<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A MySQL server that hosts tenant databases (spec §6.6).
 *
 * @property int $id
 * @property string $name
 * @property string $host
 * @property int $port
 * @property string|null $read_host
 * @property string $username
 * @property string $password
 * @property int $max_tenants
 * @property int $tenant_count
 * @property bool $is_accepting_tenants
 */
class DatabaseServer extends Model
{
    protected $connection = 'landlord';

    protected $fillable = ['name', 'host', 'port', 'read_host', 'username', 'password', 'max_tenants', 'is_accepting_tenants'];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'encrypted',
        'port' => 'integer',
        'max_tenants' => 'integer',
        'tenant_count' => 'integer',
        'is_accepting_tenants' => 'boolean',
    ];

    public function utilisation(): float
    {
        return $this->max_tenants > 0 ? $this->tenant_count / $this->max_tenants : 1.0;
    }
}
