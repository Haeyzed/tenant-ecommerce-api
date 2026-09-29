<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

use App\Contracts\ModuleLifecycle;
use App\Modules\Hr\Models\HrPayrollRun;

/**
 * The payroll submodule's lifecycle (spec §11.5): it cannot be disabled
 * while a run is finalized but not yet paid; pay it first.
 */
final class HrPayrollLifecycle implements ModuleLifecycle
{
    public function seedDefaults(): void {}

    public function disableBlockers(): array
    {
        $unpaid = HrPayrollRun::query()->where('status', HrPayrollRun::FINALIZED)->count();

        return $unpaid === 0 ? [] : ["{$unpaid} finalized payroll ".($unpaid === 1 ? 'run is' : 'runs are').' not paid yet. Pay them first.'];
    }

    public function onEnabled(): void {}

    public function onDisabled(): void {}
}
