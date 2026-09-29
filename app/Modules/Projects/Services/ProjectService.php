<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectCategory;
use App\Modules\Users\Models\User;
use App\Modules\Users\Support\StaffAccessScope;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Projects (spec §63.3). "Employees" here are staff users. A staff user
 * narrowed by staff_data_access_scope (§25.3) sees only the projects they
 * are assigned to or created. The WhatsApp toggles decide whether the
 * notifications of §63.2 are sent at all.
 */
final readonly class ProjectService
{
    public const string ENTITY = 'project';

    public function __construct(
        private StaffAccessScope $scope,
        private NotificationDispatchService $notifications,
        private CustomFieldService $customFields,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $userIds
     */
    public function createProject(array $data, array $userIds, User $by): Project
    {
        [$validated, $custom] = $this->validate($data, null);
        $users = $this->activeUserIds($userIds);

        $project = DB::connection('tenant')->transaction(function () use ($validated, $custom, $users, $by): Project {
            $project = new Project;
            $project->forceFill([...$validated, 'created_by_user_id' => $by->id,
                'completed_at' => ($validated['status'] ?? null) === Project::COMPLETED ? now() : null])->save();
            $project->users()->sync($users);
            $this->customFields->save($project, self::ENTITY, $custom);

            return $project;
        });

        $this->notifyAssigned($project, $users);

        return $project;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProject(Project $project, array $data): Project
    {
        [$validated, $custom] = $this->validate($data, $project);
        $status = $validated['status'] ?? null;
        unset($validated['status']);

        DB::connection('tenant')->transaction(function () use ($project, $validated, $custom): void {
            $project->forceFill($validated)->save();
            $this->customFields->save($project, self::ENTITY, $custom);
        });

        return $status === null ? $project : $this->updateStatus($project, $status);
    }

    /**
     * Replaces the assigned users; the newly added are notified.
     *
     * @param  list<int>  $userIds
     */
    public function assignEmployees(Project $project, array $userIds): Project
    {
        $users = $this->activeUserIds($userIds);
        $changes = $project->users()->sync($users);
        $this->notifyAssigned($project, $changes['attached']);

        return $project;
    }

    public function updateStatus(Project $project, string $status): Project
    {
        Validator::make(['status' => $status], ['status' => ['required', Rule::in(Project::STATUSES)]])->validate();

        if ($project->status === $status) {
            return $project;
        }

        $project->forceFill(['status' => $status, 'completed_at' => $status === Project::COMPLETED ? now() : null])->save();

        if ($project->notify_customer_whatsapp && $project->customer_id !== null) {
            // The full row: callers may have loaded the customer with a few columns.
            $customer = Customer::query()->find($project->customer_id);

            if ($customer !== null && $customer->anonymized_at === null) {
                $this->notifications->dispatch('project.customer_update', $customer, [
                    'customer_name' => $customer->name,
                    'project_name' => $project->title,
                    'status' => str_replace('_', ' ', $status),
                ]);
            }
        }

        return $project;
    }

    public function deleteProject(Project $project): void
    {
        DB::connection('tenant')->transaction(function () use ($project): void {
            $this->customFields->forget($project, self::ENTITY);
            $project->delete();
        });
    }

    /**
     * @param  array{project_category_id?: int, customer_id?: int, status?: string, priority?: string, user_id?: int, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Project>
     */
    public function listProjects(array $filters, User $viewer): LengthAwarePaginator
    {
        $search = isset($filters['search']) ? '%'.addcslashes((string) $filters['search'], '%_\\').'%' : null;

        return $this->visible($viewer)->with(['category:id,name', 'customer:id,name', 'users:id,name'])
            ->withCount(['tasks', 'tasks as open_tasks_count' => static fn ($q) => $q->whereNotIn('status', Project::CLOSED)])
            ->when(isset($filters['project_category_id']), static fn ($q) => $q->where('project_category_id', $filters['project_category_id']))
            ->when(isset($filters['customer_id']), static fn ($q) => $q->where('customer_id', $filters['customer_id']))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['priority']), static fn ($q) => $q->where('priority', $filters['priority']))
            ->when(isset($filters['user_id']), static fn ($q) => $q->whereHas('users', static fn ($u) => $u->where('users.id', $filters['user_id'])))
            ->when($search !== null, static fn ($q) => $q->where('title', 'like', $search))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * A project the viewer may see, else 404.
     */
    public function getProject(Project $project, User $viewer): Project
    {
        return $this->visible($viewer)->with(['category:id,name', 'customer:id,name,email', 'users:id,name,email', 'creator:id,name'])->find($project->id)
            ?? throw new NotFoundHttpException('Not found.');
    }

    /**
     * Narrowed users (§25.3) see the projects they are assigned to or created.
     *
     * @return Builder<Project>
     */
    public function visible(User $viewer): Builder
    {
        return Project::query()->when($this->scope->mode($viewer) !== StaffAccessScope::ALL, static fn ($q) => $q->where(static fn ($w) => $w
            ->where('created_by_user_id', $viewer->id)
            ->orWhereHas('users', static fn ($u) => $u->where('users.id', $viewer->id))));
    }

    public function narrowed(User $viewer): bool
    {
        return $this->scope->mode($viewer) !== StaffAccessScope::ALL;
    }

    /**
     * @param  list<int>  $userIds
     */
    private function notifyAssigned(Project $project, array $userIds): void
    {
        if (! $project->notify_assigned_employees_whatsapp || $userIds === []) {
            return;
        }

        $users = User::query()->whereKey($userIds)->where('is_active', true)->get();

        if ($users->isNotEmpty()) {
            $this->notifications->dispatch('project.assigned', $users->all(), ['project_name' => $project->title], data: ['project_id' => $project->id]);
        }
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function activeUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $found = User::query()->whereKey($userIds)->where('is_active', true)->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        if (count($found) !== count($userIds)) {
            throw ApiException::unprocessable('user_invalid', 'Assign active staff users only.', ['user_ids' => array_values(array_diff($userIds, $found))]);
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validate(array $data, ?Project $existing): array
    {
        $creating = $existing === null;
        $validator = Validator::make($data, [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'project_category_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('tenant.project_categories', 'id')->where('status', 'active')],
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.customers', 'id')->whereNull('deleted_at')],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'priority' => ['sometimes', Rule::in(Project::PRIORITIES)],
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
            'notify_assigned_employees_whatsapp' => ['sometimes', 'boolean'],
            'notify_customer_whatsapp' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'custom_fields' => ['sometimes', 'array'],
        ]);

        $errors = $validator->errors()->toArray();
        $custom = [];

        try {
            $custom = $this->customFields->validate(self::ENTITY, (array) ($data['custom_fields'] ?? []), CustomFieldService::ADMIN, $creating);
        } catch (ValidationException $e) {
            $errors = array_merge($errors, $e->errors());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $validated = $validator->validated();
        unset($validated['custom_fields']);

        if (! $creating && isset($validated['project_category_id']) && ! ProjectCategory::query()->whereKey($validated['project_category_id'])->exists()) {
            throw ApiException::unprocessable('project_category_invalid', 'Choose an active category.');
        }

        return [$validated, $custom];
    }
}
