<?php

declare(strict_types=1);

namespace App\Modules\Hr\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Http\HrPresenter;
use App\Modules\Hr\Models\HrPayrollItem;
use App\Modules\Hr\Models\HrPayrollItemLine;
use App\Modules\Hr\Models\HrPayrollRun;
use App\Modules\Hr\Services\HrPayrollService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payslip lines (spec §58.5). Lines change only while the run is processing.
 */
final class PayrollItemController extends Controller
{
    public function __construct(
        private readonly HrPayrollService $payroll,
        private readonly HrPresenter $presenter,
    ) {}

    public function index(HrPayrollRun $run): JsonResponse
    {
        return APIResponse::success($this->payroll->listPayrollItems($run)->map(fn (HrPayrollItem $i): array => $this->presenter->item($i))->all());
    }

    public function show(HrPayrollItem $item): JsonResponse
    {
        return APIResponse::success($this->presenter->item($item->load('run')));
    }

    /**
     * Body: type (allowance | deduction | tax | bonus | reimbursement), label,
     * amount, is_percentage? (then amount is a percentage of the base salary).
     */
    public function addLine(Request $request, HrPayrollItem $item): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string'],
            'label' => ['required', 'string'],
            'amount' => ['required', 'numeric'],
            'is_percentage' => ['sometimes', 'boolean'],
        ]);

        $this->payroll->addPayrollItemLine($item, $validated['type'], $validated['label'], (string) $validated['amount'], (bool) ($validated['is_percentage'] ?? false));

        return APIResponse::created($this->presenter->item($item->fresh(['run', 'lines'])), 'Line added');
    }

    public function removeLine(HrPayrollItemLine $line): JsonResponse
    {
        $itemId = $line->payroll_item_id;
        $this->payroll->removePayrollItemLine($line);

        return APIResponse::success($this->presenter->item(HrPayrollItem::query()->with(['run', 'lines'])->findOrFail($itemId)), 'Line removed');
    }

    public function recalculate(HrPayrollItem $item): JsonResponse
    {
        return APIResponse::success($this->presenter->item($this->payroll->recalculatePayrollItem($item)->load(['run', 'lines'])), 'Payslip recalculated');
    }

    public function markPaid(HrPayrollItem $item): JsonResponse
    {
        return APIResponse::success($this->presenter->item($this->payroll->markPayrollItemPaid($item)->load(['run', 'lines'])), 'Payslip paid');
    }
}
