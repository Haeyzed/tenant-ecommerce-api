<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\ProjectPresenter;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Projects (spec §63.4). A narrowed staff user (§25.3) sees only the
 * projects they are assigned to or created; others are a 404.
 */
final class ProjectController extends Controller
{
    private const array FIELDS = ['title', 'project_category_id', 'customer_id', 'start_date', 'end_date', 'priority', 'status',
        'notify_assigned_employees_whatsapp', 'notify_customer_whatsapp', 'description', 'custom_fields'];

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectPresenter $presenter,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'project_category_id' => ['sometimes', 'integer'],
            'customer_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
            'priority' => ['sometimes', Rule::in(Project::PRIORITIES)],
            'user_id' => ['sometimes', 'integer'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return APIResponse::success($this->projects->listProjects($filters, $this->user($request))->through(fn (Project $p): array => $this->presenter->project($p)));
    }

    /**
     * Body: title, project_category_id, customer_id?, start_date?, end_date?,
     * priority?, status?, the two WhatsApp toggles?, description?, user_ids?, custom_fields?
     */
    public function store(Request $request): JsonResponse
    {
        $userIds = $request->validate(['user_ids' => ['sometimes', 'array', 'max:100'], 'user_ids.*' => ['integer']])['user_ids'] ?? [];
        $project = $this->projects->createProject($request->only(self::FIELDS), array_map('intval', $userIds), $this->user($request));

        return APIResponse::created($this->presenter->project($this->projects->getProject($project, $this->user($request)), true), 'Project created');
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        return APIResponse::success($this->presenter->project($this->projects->getProject($project, $this->user($request)), true));
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $project = $this->projects->getProject($project, $this->user($request));
        $this->projects->updateProject($project, $request->only(self::FIELDS));

        return APIResponse::success($this->presenter->project($this->projects->getProject($project, $this->user($request)), true), 'Project updated');
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->projects->deleteProject($this->projects->getProject($project, $this->user($request)));

        return APIResponse::success(null, 'Project deleted');
    }

    /**
     * Body: user_ids (replaces the assignment).
     */
    public function assignEmployees(Request $request, Project $project): JsonResponse
    {
        $userIds = $request->validate(['user_ids' => ['present', 'array', 'max:100'], 'user_ids.*' => ['integer']])['user_ids'];
        $project = $this->projects->getProject($project, $this->user($request));
        $this->projects->assignEmployees($project, array_map('intval', $userIds));

        return APIResponse::success($this->presenter->project($project->load('users:id,name'), true), 'Employees assigned');
    }

    /**
     * Body: status.
     */
    public function updateStatus(Request $request, Project $project): JsonResponse
    {
        $project = $this->projects->getProject($project, $this->user($request));

        return APIResponse::success($this->presenter->project($this->projects->updateStatus($project, (string) $request->input('status', ''))), 'Status updated');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
