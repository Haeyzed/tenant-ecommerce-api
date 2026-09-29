<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use App\Modules\Imports\Models\DataImport;
use Closure;

/**
 * One import type (D-135), registered by the module that owns the data.
 *
 * apply receives one row (heading => value, headings slugged with "_")
 * and the import, and applies it through the owning service, so every
 * business rule of the API applies. It returns 'created', 'updated' or
 * 'skipped', and throws (validation, business rule) to reject the row.
 */
final readonly class ImportDefinition
{
    /**
     * @param  array<string, string>  $columns  heading => what it holds (the template and the docs)
     * @param  list<string>  $required  headings the file must have
     * @param  Closure(array<string, mixed>, DataImport): string  $apply
     * @param  list<string>  $modes  modes this type accepts; the first is the default
     * @param  list<string>  $sensitive  headings whose values are never echoed in errors
     */
    public function __construct(
        public string $type,
        public string $label,
        public array $columns,
        public array $required,
        public Closure $apply,
        public string $permission,
        public array $modes = ['upsert', 'create', 'update'],
        public ?string $module = null,
        public array $sensitive = [],
    ) {}
}
