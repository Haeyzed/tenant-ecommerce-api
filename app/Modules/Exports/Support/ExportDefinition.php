<?php

declare(strict_types=1);

namespace App\Modules\Exports\Support;

use Closure;

/**
 * One export type (spec §19.4), registered by the module that owns the data.
 *
 * rows receives the validated parameters and returns a lazy iterable of
 * associative rows (the header is taken from `columns`), so a type never
 * loads its data into memory.
 */
final readonly class ExportDefinition
{
    /**
     * @param  array<string, list<mixed>>  $rules  validation of the parameters
     * @param  array<string, string>  $columns  row key => header label
     * @param  Closure(array<string, mixed>): iterable<array<string, mixed>>  $rows
     * @param  list<string>  $formats
     * @param  string|null  $permission  required of staff requesters; null for customer self-service types
     * @param  string|null  $module  feature key whose data this is; canRead() must hold (§19.4)
     */
    public function __construct(
        public string $type,
        public string $label,
        public array $rules,
        public array $columns,
        public Closure $rows,
        public array $formats = ['csv', 'json'],
        public ?string $permission = null,
        public ?string $module = null,
    ) {}
}
