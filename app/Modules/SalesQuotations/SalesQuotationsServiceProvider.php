<?php

declare(strict_types=1);

namespace App\Modules\SalesQuotations;

use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\SalesQuotations\Models\SalesQuotation;
use App\Modules\SalesQuotations\Services\SalesQuotationService;
use Illuminate\Support\ServiceProvider;

/**
 * Wires quotations into the custom-field registry (§23.1).
 */
final class SalesQuotationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(CustomFieldEntityRegistry::class, static function (CustomFieldEntityRegistry $registry): void {
            $registry->register(SalesQuotationService::ENTITY, SalesQuotation::class, 'sales-quotation-requests', 'sales_quotations');
        });
    }
}
