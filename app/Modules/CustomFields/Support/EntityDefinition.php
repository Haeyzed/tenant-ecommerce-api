<?php

declare(strict_types=1);

namespace App\Modules\CustomFields\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A registered custom-field entity (spec §23.1).
 */
final readonly class EntityDefinition
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public string $type,
        public string $model,
        public string $permissionResource,
        public ?string $ownerModule,
    ) {}
}
