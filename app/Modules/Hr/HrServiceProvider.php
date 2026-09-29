<?php

declare(strict_types=1);

namespace App\Modules\Hr;

use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Services\HrEmployeeService;
use App\Shared\Support\UsageCounterRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * HR wiring (spec §58): the max_employees counter (active employees,
 * §11.8) and the employee custom-field entity (§23.1).
 */
final class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(UsageCounterRegistry::class, static function (UsageCounterRegistry $registry): void {
            $registry->register('max_employees', static fn (): int => HrEmployeeService::countActive());
        });

        $this->app->afterResolving(CustomFieldEntityRegistry::class, static function (CustomFieldEntityRegistry $registry): void {
            $registry->register(HrEmployeeService::ENTITY, HrEmployee::class, 'hr.employees', 'hr');
        });
    }
}
