<?php

declare(strict_types=1);

namespace App\Modules\Repair\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\Customer;
use App\Modules\Repair\Http\RepairPresenter;
use App\Modules\Repair\Models\RepairJob;
use App\Modules\Repair\Services\RepairJobService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's own repair jobs (spec §67.4), and their go-ahead on an
 * estimate.
 */
final class RepairJobController extends Controller
{
    public function __construct(
        private readonly RepairJobService $jobs,
        private readonly RepairPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return APIResponse::success($this->jobs->listForCustomer($this->customer($request))->map(fn (RepairJob $j): array => $this->presenter->job($j, false))->values()->all());
    }

    public function show(Request $request, RepairJob $job): JsonResponse
    {
        return APIResponse::success($this->presenter->job($this->jobs->getJob($this->own($request, $job)), false));
    }

    public function approve(Request $request, RepairJob $job): JsonResponse
    {
        return APIResponse::success($this->presenter->job($this->jobs->recordCustomerApproval($this->own($request, $job)), false), 'Estimate approved');
    }

    private function own(Request $request, RepairJob $job): RepairJob
    {
        return $job->customer_id === $this->customer($request)->id ? $job : throw new NotFoundHttpException('Not found.');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user();
    }
}
