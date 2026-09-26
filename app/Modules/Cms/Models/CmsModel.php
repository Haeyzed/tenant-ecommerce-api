<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\CmsScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Base of every CMS model (spec §24.1): the connection follows the scope,
 * so no CMS model hard-codes one.
 */
abstract class CmsModel extends Model
{
    public function getConnectionName(): string
    {
        return CmsScope::current();
    }
}
