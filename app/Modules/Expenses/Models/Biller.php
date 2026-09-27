<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A payee for bills that are not stock purchases (spec §57.4).
 *
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string $category utility | rent | landlord | vendor | other
 * @property string|null $account_reference
 * @property string|null $notes
 * @property bool $is_active
 */
class Biller extends Model
{
    public const array CATEGORIES = ['utility', 'rent', 'landlord', 'vendor', 'other'];

    protected $connection = 'tenant';

    protected $fillable = ['name', 'phone', 'email', 'category', 'account_reference', 'notes'];

    protected $casts = ['is_active' => 'boolean'];
}
