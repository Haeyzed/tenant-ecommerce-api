<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Why stock goes back to a supplier (spec §49.5), curated separately from
 * customer return reasons.
 *
 * @property int $id
 * @property string $label
 * @property bool $is_active
 */
class PurchaseReturnReason extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['label'];

    protected $casts = ['is_active' => 'boolean'];
}
