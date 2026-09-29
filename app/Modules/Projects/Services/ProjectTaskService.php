<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Project tasks (spec §63.3). A narrowed staff user (§25.3) sees only the
 * tasks assigned to them. project_task.assigned is sent when a task is
 * created with, or reassigned to, a user and its WhatsApp toggle is on.
 */
final readonly class ProjectTaskService
{
    public function __construct(
        private ProjectService $projects,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTask(Project $project, array $data): ProjectTask
    {
        $validated = $this->validate($data, true);

        $task = new ProjectTask;
        $task->forceFill([...$validated, 'project_id' => $project->id,
            'completed_at' => ($validated['status'] ?? null) === Project::COMPLETED ? now() : null])->save();

        $this->notifyAssignee($task);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTask(ProjectTask $task, array $data): ProjectTask
    {
        $validated = $this->validate($data, false);
        $status = $validated['status'] ?? null;
        unset($validated['status']);
        $reassigned = array_key_exists('assigned_user_id', $validated) && $validated['assigned_user_id'] !== null
            && (int) $validated['assigned_user_id'] !== $task->assigned_user_id;

        $task->forceFill($validated)->save();

        if ($reassigned) {
            $this->notifyAssignee($task);
        }

        return $status === null ? $task : $this->updateStatus($task, $status);
    }

    public function updateStatus(ProjectTask $task, string $status): ProjectTask
    {
        Validator::make(['status' => $status], ['status' => ['required', Rule::in(Project::STATUSES)]])->validate();

        if ($task->status !== $status) {
            $task->forceFill(['status' => $status, 'completed_at' => $status === Project::COMPLETED ? now() : null])->save();
        }

        return $task;
    }

    public function deleteTask(ProjectTask $task): void
    {
        $task->delete();
    }

    /**
     * @param  array{status?: string, assigned_user_id?: int, overdue?: bool}  $filters
     * @return Collection<int, ProjectTask>
     */
    public function listTasks(Project $project, array $filters, User $viewer): Collection
    {
        return ProjectTask::query()->with('assignee:id,name')->where('project_id', $project->id)
            ->when($this->projects->narrowed($viewer), static fn ($q) => $q->where('assigned_user_id', $viewer->id))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['assigned_user_id']), static fn ($q) => $q->where('assigned_user_id', $filters['assigned_user_id']))
            ->when((bool) ($filters['overdue'] ?? false), static fn ($q) => $q->whereNotIn('status', Project::CLOSED)->whereDate('end_date', '<', today()))
            ->orderByRaw('end_date IS NULL')->orderBy('end_date')->orderBy('id')
            ->get();
    }

    /**
     * The task belongs to the project and, for a narrowed user, to them.
     */
    public function assertVisible(Project $project, ProjectTask $task, User $viewer): void
    {
        if ($task->project_id !== $project->id || ($this->projects->narrowed($viewer) && $task->assigned_user_id !== $viewer->id)) {
            throw new NotFoundHttpException('Not found.');
        }
    }

    private function notifyAssignee(ProjectTask $task): void
    {
        if (! $task->send_whatsapp_notification || $task->assigned_user_id === null) {
            return;
        }

        $task->loadMissing(['assignee', 'project']);

        if ($task->assignee !== null && $task->assignee->is_active) {
            $this->notifications->dispatch('project_task.assigned', $task->assignee, [
                'task_title' => $task->title,
                'project_name' => $task->project->title,
                'due_on' => $task->end_date?->toDateString() ?? 'no date set',
            ], data: ['project_id' => $task->project_id, 'task_id' => $task->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        return Validator::make($data, [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'estimated_hours' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999', 'decimal:0,2'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.users', 'id')->where('is_active', true)],
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
            'send_whatsapp_notification' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ])->validate();
    }
}
