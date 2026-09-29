<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\ProjectPresenter;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Projects\Services\ProjectTaskService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tasks of a project (spec §63.4). A narrowed staff user sees only the
 * tasks assigned to them.
 */
final class ProjectTaskController extends Controller
{
    private const array FIELDS = ['title', 'start_date', 'end_date', 'estimated_hours', 'assigned_user_id', 'status', 'send_whatsapp_notification', 'description'];

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectTaskService $tasks,
        private readonly ProjectPresenter $presenter,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
            'assigned_user_id' => ['sometimes', 'integer'],
            'overdue' => ['sometimes', 'boolean'],
        ]);
        $project = $this->projects->getProject($project, $this->user($request));

        return APIResponse::success($this->tasks->listTasks($project, $filters, $this->user($request))->map(fn (ProjectTask $t): array => $this->presenter->task($t))->all());
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $project = $this->projects->getProject($project, $this->user($request));

        return APIResponse::created($this->presenter->task($this->tasks->createTask($project, $request->only(self::FIELDS))), 'Task created');
    }

    public function update(Request $request, Project $project, ProjectTask $task): JsonResponse
    {
        $this->visible($request, $project, $task);

        return APIResponse::success($this->presenter->task($this->tasks->updateTask($task, $request->only(self::FIELDS))), 'Task updated');
    }

    public function destroy(Request $request, Project $project, ProjectTask $task): JsonResponse
    {
        $this->visible($request, $project, $task);
        $this->tasks->deleteTask($task);

        return APIResponse::success(null, 'Task deleted');
    }

    /**
     * Body: status.
     */
    public function updateStatus(Request $request, Project $project, ProjectTask $task): JsonResponse
    {
        $this->visible($request, $project, $task);

        return APIResponse::success($this->presenter->task($this->tasks->updateStatus($task, (string) $request->input('status', ''))), 'Status updated');
    }

    private function visible(Request $request, Project $project, ProjectTask $task): void
    {
        $project = $this->projects->getProject($project, $this->user($request));
        $this->tasks->assertVisible($project, $task, $this->user($request));
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
