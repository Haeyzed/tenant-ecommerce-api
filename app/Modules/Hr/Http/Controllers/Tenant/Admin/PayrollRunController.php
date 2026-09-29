<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrPayrollItem;
use App\Modules\Hr\Models\HrPayrollRun;
use App\Modules\Hr\Services\HrPayrollService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payroll runs (spec §58.5): create, generate the items, adjust the lines,
 * finalize, pay.
 */
final class PayrollRunController extends Controller
{
    public function __construct(
        private readonly HrPayrollService $payroll,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(HrPayrollRun::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->payroll->listPayrollRuns($filters)->through(fn (HrPayrollRun $r): array => $this->presenter->run($r)));
    }

    /**
     * Body: period_start, period_end.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::created($this->presenter->run($this->payroll->createPayrollRun((string) $request->input('period_start'), (string) $request->input('period_end'), $user)), 'Payroll run created');
    }

    public function show(HrPayrollRun $run): JsonResponse
    {
        return APIResponse::success($this->detail($run));
    }

    public function generateItems(HrPayrollRun $run): JsonResponse
    {
        return APIResponse::success($this->detail($this->payroll->generatePayrollItemsForRun($run)), 'Payroll items generated');
    }

    public function finalize(Request $request, HrPayrollRun $run): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return APIResponse::success($this->detail($this->payroll->finalizePayrollRun($run, $user)), 'Payroll run finalized');
    }

    public function markPaid(HrPayrollRun $run): JsonResponse
    {
        return APIResponse::success($this->detail($this->payroll->markPayrollRunPaid($run)), 'Payroll run paid');
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(HrPayrollRun $run): array
    {
        return [
            ...$this->presenter->run($run->refresh()),
            'items' => $this->payroll->listPayrollItems($run)->map(fn (HrPayrollItem $i): array => $this->presenter->item($i))->all(),
        ];
    }
}
