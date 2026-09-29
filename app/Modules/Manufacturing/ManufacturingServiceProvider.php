<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing;

use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Manufacturing\Models\WorkOrder;
use Illuminate\Support\ServiceProvider;

/**
 * Wires work orders into the custom-field registry (§23.1).
 */
final class ManufacturingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(CustomFieldEntityRegistry::class, static function (CustomFieldEntityRegistry $registry): void {
            $registry->register('work_order', WorkOrder::class, 'work-orders', 'manufacturing');
        });
    }
}
