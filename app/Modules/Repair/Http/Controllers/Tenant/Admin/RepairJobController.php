<?php

declare(strict_types=1);

namespace App\Modules\Repair\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Repair\Http\RepairPresenter;
use App\Modules\Repair\Models\RepairJob;
use App\Modules\Repair\Services\RepairJobService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Repair jobs (spec §67.4). Staff narrowed by data-access scope (§25.3)
 * see the jobs of their locations (or those they took in).
 */
final class RepairJobController extends Controller
{
    public function __construct(
        private readonly RepairJobService $jobs,
        private readonly RepairPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(RepairJob::STATUSES)],
            'customer_id' => ['sometimes', 'integer'],
            'warehouse_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->jobs->listJobs($filters, $this->user($request))->through(fn (RepairJob $j): array => $this->presenter->job($j, true)));
    }

    /**
     * Body: item_description, warehouse_id, customer_id? | customer_name, customer_phone?, booking_id?
     */
    public function store(Request $request): JsonResponse
    {
        $job = $this->jobs->createJob($request->only(['item_description', 'warehouse_id', 'customer_id', 'customer_name', 'customer_phone', 'booking_id']), $this->user($request));

        return APIResponse::created($this->presenter->job($job, true), 'Repair job received');
    }

    public function show(Request $request, RepairJob $job): JsonResponse
    {
        return APIResponse::success($this->presenter->job($this->jobs->getJob($job, $this->user($request)), true));
    }

    /**
     * Body: diagnosis_notes, estimated_cost? (null = no approval needed)
     */
    public function diagnosis(Request $request, RepairJob $job): JsonResponse
    {
        $data = $request->validate(['diagnosis_notes' => ['required', 'string'], 'estimated_cost' => ['sometimes', 'nullable', 'numeric']]);
        $job = $this->jobs->updateDiagnosis($this->jobs->getJob($job, $this->user($request)), $data['diagnosis_notes'], isset($data['estimated_cost']) ? (string) $data['estimated_cost'] : null);

        return APIResponse::success($this->presenter->job($job, true), 'Diagnosis saved');
    }

    public function recordApproval(Request $request, RepairJob $job): JsonResponse
    {
        return APIResponse::success($this->presenter->job($this->jobs->recordCustomerApproval($this->jobs->getJob($job, $this->user($request))), true), 'Approval recorded');
    }

    /**
     * Body: status (diagnosing | in_repair | completed | picked_up | cancelled)
     */
    public function updateStatus(Request $request, RepairJob $job): JsonResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(RepairJob::STATUSES)]])['status'];

        return APIResponse::success($this->presenter->job($this->jobs->updateStatus($this->jobs->getJob($job, $this->user($request)), $status), true), 'Status updated');
    }

    public function generateInvoice(Request $request, RepairJob $job): JsonResponse
    {
        $order = $this->jobs->generateInvoice($this->jobs->getJob($job, $this->user($request)), $this->user($request));

        return APIResponse::created(['job' => $this->presenter->job($this->jobs->getJob($job->refresh()), true), 'order_id' => $order->id], 'Invoice created');
    }

    public function pickedUp(Request $request, RepairJob $job): JsonResponse
    {
        return APIResponse::success($this->presenter->job($this->jobs->markPickedUp($this->jobs->getJob($job, $this->user($request))), true), 'Picked up');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
