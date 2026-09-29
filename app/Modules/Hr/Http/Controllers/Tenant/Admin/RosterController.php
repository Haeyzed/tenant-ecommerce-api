<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\Controllers\Tenant\Admin\Concerns\ResolvesActingEmployee;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrShiftAssignment;
use App\Modules\Hr\Services\HrShiftService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shift roster (spec §58.3a). Staff see their own schedule without an
 * HR permission; rostering others needs the derived permissions.
 */
final class RosterController extends Controller
{
    use ResolvesActingEmployee;

    /** Reading another employee's schedule through the self-service route. */
    public const string VIEW = 'hr.roster.view';

    public function __construct(
        private readonly HrShiftService $shifts,
        private readonly HrPresenter $presenter,
    ) {}

    /**
     * Query: from, to (at most 93 days), employee_id?, department_id?, shift_id?
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'employee_id' => ['sometimes', 'integer'],
            'department_id' => ['sometimes', 'integer'],
            'shift_id' => ['sometimes', 'integer'],
        ]) + $this->range($request);

        return APIResponse::success($this->shifts->roster($filters)->map(fn (HrShiftAssignment $a): array => $this->presenter->assignment($a))->all());
    }

    /**
     * The signed-in employee's own shifts. Query: from?, to?
     */
    public function mine(Request $request): JsonResponse
    {
        $employee = $this->actingEmployee($request, self::VIEW);

        return APIResponse::success($this->shifts->roster(['employee_id' => $employee->id] + $this->range($request))
            ->map(fn (HrShiftAssignment $a): array => $this->presenter->assignment($a))->all());
    }

    /**
     * Body: employee_ids[], shift_id, from, to, weekdays? (1 = Monday … 7 = Sunday), replace?, notes?
     */
    public function assign(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::success($this->shifts->assign($request->all(), $user), 'Roster updated');
    }

    /**
     * Body: employee_ids[], from, to.
     */
    public function unassign(Request $request): JsonResponse
    {
        return APIResponse::success(['removed' => $this->shifts->unassign($request->all())], 'Roster updated');
    }

    /**
     * @return array{from: string, to: string}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($validated['from'] ?? today()->toDateString());
        $to = CarbonImmutable::parse($validated['to'] ?? $from->addDays(6)->toDateString());

        return ['from' => $from->toDateString(), 'to' => ($from->diffInDays($to) > 92 ? $from->addDays(92) : $to)->toDateString()];
    }
}
