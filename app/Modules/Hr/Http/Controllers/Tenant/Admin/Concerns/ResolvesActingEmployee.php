<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin\Concerns;

use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Self-service HR routes (spec §58.3, §58.4): the authenticated user's
 * linked employee, or, with the named permission, the employee_id given.
 */
trait ResolvesActingEmployee
{
    private function actingEmployee(Request $request, string $permission): HrEmployee
    {
        /** @var User $user */
        $user = $request->user();
        $employeeId = $request->validate(['employee_id' => ['sometimes', 'nullable', 'integer']])['employee_id'] ?? null;

        if ($employeeId !== null) {
            $own = HrEmployee::query()->where('user_id', $user->id)->value('id');

            if ((int) $employeeId !== $own && ! $user->hasPermissionTo($permission, 'staff')) {
                throw ApiException::forbidden('forbidden', 'You may only act on your own employee record.', ['permission' => $permission]);
            }

            return HrEmployee::query()->find((int) $employeeId) ?? throw new NotFoundHttpException('Not found.');
        }

        return HrEmployee::query()->where('user_id', $user->id)->first()
            ?? throw ApiException::unprocessable('no_linked_employee', 'Your user account is not linked to an employee record.');
    }

    private function isOwn(Request $request, HrEmployee $employee): bool
    {
        return $employee->user_id !== null && $employee->user_id === $request->user()?->getKey();
    }
}
