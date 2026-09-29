<?php

declare(strict_types=1);

namespace App\Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One rejected cell or row of an import (D-135). The submitted value is
 * kept only for non-sensitive columns and is truncated.
 *
 * @property int $id
 * @property int $data_import_id
 * @property int $row_number
 * @property string|null $field
 * @property string $message
 * @property string|null $value
 */
class DataImportError extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [];

    protected $casts = ['data_import_id' => 'integer', 'row_number' => 'integer'];
}
