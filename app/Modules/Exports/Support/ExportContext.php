<?php

declare(strict_types=1);

namespace App\Modules\Exports\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The export being generated (spec §19.4), bound by GenerateExport while a
 * type produces its rows. Types whose data depends on who asks (reports
 * narrowed by the staff scope, §25.3) read the requester here, never from
 * the parameters, which a caller could forge.
 */
final readonly class ExportContext
{
    public function __construct(public ?Model $requestedBy) {}
}
