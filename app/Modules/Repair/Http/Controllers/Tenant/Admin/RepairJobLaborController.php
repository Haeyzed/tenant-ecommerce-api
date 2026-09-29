<?php

declare(strict_types=1);

namespace App\Modules\Repair\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Repair\Http\RepairPresenter;
use App\Modules\Repair\Models\RepairJob;
use App\Modules\Repair\Models\RepairJobLabor;
use App\Modules\Repair\Services\RepairJobService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Labour on a repair (spec §67.3).
 */
final class RepairJobLaborController extends Controller
{
    public function __construct(
        private readonly RepairJobService $jobs,
        private readonly RepairPresenter $presenter,
    ) {}

    /**
     * Body: description, amount (base currency)
     */
    public function store(Request $request, RepairJob $job): JsonResponse
    {
        $data = $request->validate(['description' => ['required', 'string'], 'amount' => ['required', 'numeric']]);
        $labor = $this->jobs->addLabor($this->jobs->getJob($job, $this->user($request)), $data['description'], (string) $data['amount']);

        return APIResponse::created($this->presenter->labor($labor), 'Labour added');
    }

    public function destroy(Request $request, RepairJob $job, RepairJobLabor $labor): JsonResponse
    {
        if ($labor->repair_job_id !== $job->id) {
            throw new NotFoundHttpException('Not found.');
        }

        $this->jobs->removeLabor($this->jobs->getJob($job, $this->user($request)), $labor);

        return APIResponse::success(null, 'Labour removed');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
