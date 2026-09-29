<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectCategory;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Users\Models\User;

final readonly class ProjectPresenter
{
    public function __construct(private CustomFieldService $customFields) {}

    /**
     * @return array<string, mixed>
     */
    public function category(ProjectCategory $category): array
    {
        return ['id' => $category->id, 'name' => $category->name, 'status' => $category->status, 'projects_count' => $category->getAttributes()['projects_count'] ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    public function project(Project $project, bool $detail = false): array
    {
        $project->loadMissing(['category:id,name', 'customer:id,name', 'users:id,name']);
        $payload = [
            'id' => $project->id,
            'title' => $project->title,
            'category' => ['id' => $project->project_category_id, 'name' => $project->category->name],
            'customer' => $project->customer === null ? null : ['id' => $project->customer->id, 'name' => $project->customer->name],
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'priority' => $project->priority,
            'status' => $project->status,
            'assigned_users' => $project->users->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values()->all(),
            'notify_assigned_employees_whatsapp' => $project->notify_assigned_employees_whatsapp,
            'notify_customer_whatsapp' => $project->notify_customer_whatsapp,
            'tasks_count' => $project->getAttributes()['tasks_count'] ?? null,
            'open_tasks_count' => $project->getAttributes()['open_tasks_count'] ?? null,
            'completed_at' => $project->completed_at?->toIso8601String(),
            'created_at' => $project->created_at->toIso8601String(),
        ];

        if ($detail) {
            $payload['description'] = $project->description;
            $payload['created_by'] = $project->relationLoaded('creator') ? ['id' => $project->creator->id, 'name' => $project->creator->name] : ['id' => $project->created_by_user_id];
            $payload['custom_fields'] = $this->customFields->valuesFor($project, ProjectService::ENTITY, CustomFieldService::ADMIN);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function task(ProjectTask $task): array
    {
        $task->loadMissing('assignee:id,name');

        return [
            'id' => $task->id,
            'project_id' => $task->project_id,
            'title' => $task->title,
            'start_date' => $task->start_date?->toDateString(),
            'end_date' => $task->end_date?->toDateString(),
            'estimated_hours' => $task->estimated_hours === null ? null : (string) $task->estimated_hours,
            'assignee' => $task->assignee === null ? null : ['id' => $task->assignee->id, 'name' => $task->assignee->name],
            'status' => $task->status,
            'is_overdue' => $task->isOverdue(),
            'send_whatsapp_notification' => $task->send_whatsapp_notification,
            'description' => $task->description,
            'completed_at' => $task->completed_at?->toIso8601String(),
        ];
    }
}
