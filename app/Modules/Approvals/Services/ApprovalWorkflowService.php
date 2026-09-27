<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Services;

use App\Contracts\Approvable;
use App\Modules\Access\Services\RoleService;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalStep;
use App\Modules\Approvals\Models\ApprovalStepApprover;
use App\Modules\Approvals\Models\ApprovalWorkflow;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Approval workflow configuration and matching (spec §60.2 to §60.4). A
 * workflow applies only while `approval_workflows` and its module's feature
 * are enabled. Configuration is hard-deleted; a workflow with requests is
 * deactivated instead, and its steps are frozen while requests are pending.
 */
final readonly class ApprovalWorkflowService
{
    public function __construct(private FeatureAccessService $features) {}

    /**
     * @return array<string, array{label: string, model: class-string<Model>, service: class-string<Approvable>, feature: string, conditions: list<string>}>
     */
    public static function registry(): array
    {
        return (array) config('approvals', []);
    }

    /**
     * @param  array{module_key?: string, is_active?: bool}  $filters
     * @return Collection<int, ApprovalWorkflow>
     */
    public function listWorkflows(array $filters = []): Collection
    {
        return ApprovalWorkflow::query()->with('steps.approvers.role:id,name', 'steps.approvers.user:id,name')
            ->withCount(['requests as pending_requests_count' => static fn ($q) => $q->where('status', ApprovalRequest::PENDING)])
            ->when(isset($filters['module_key']), static fn ($q) => $q->where('module_key', $filters['module_key']))
            ->when(isset($filters['is_active']), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderBy('module_key')->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data  name, module_key, trigger_conditions, sort_order
     * @param  list<array<string, mixed>>  $steps  [{name, approval_mode, approvers: [{approver_type, role_id|user_id}]}]
     */
    public function createWorkflow(array $data, array $steps): ApprovalWorkflow
    {
        $validated = $this->validateWorkflow($data, null);

        if ($steps === []) {
            throw ApiException::unprocessable('workflow_needs_steps', 'A workflow needs at least one step.');
        }

        return DB::connection('tenant')->transaction(function () use ($validated, $steps): ApprovalWorkflow {
            $workflow = new ApprovalWorkflow($validated);
            $workflow->forceFill(['is_active' => true])->save();

            foreach ($steps as $step) {
                $this->insertStep($workflow, $step);
            }

            return $workflow->load('steps.approvers');
        });
    }

    /**
     * @param  array<string, mixed>  $data  name, trigger_conditions, sort_order, is_active
     */
    public function updateWorkflow(ApprovalWorkflow $workflow, array $data): ApprovalWorkflow
    {
        $validated = $this->validateWorkflow($data, $workflow);

        if (array_key_exists('is_active', $data)) {
            $workflow->forceFill(['is_active' => (bool) validator($data, ['is_active' => ['boolean']])->validate()['is_active']]);
        }

        $workflow->fill($validated)->save();

        return $workflow->load('steps.approvers');
    }

    /**
     * @param  array<string, mixed>  $stepData  name, approval_mode, approvers
     */
    public function addStep(ApprovalWorkflow $workflow, array $stepData): ApprovalStep
    {
        return DB::connection('tenant')->transaction(function () use ($workflow, $stepData): ApprovalStep {
            $this->assertEditable($this->lockWorkflow($workflow));

            return $this->insertStep($workflow, $stepData);
        });
    }

    /**
     * Removes a step and closes the gap in step_order.
     */
    public function removeStep(ApprovalStep $step): void
    {
        DB::connection('tenant')->transaction(function () use ($step): void {
            $workflow = $this->lockWorkflow($step->workflow);
            $this->assertEditable($workflow);

            if ($workflow->steps()->count() <= 1) {
                throw ApiException::unprocessable('workflow_needs_steps', 'A workflow needs at least one step. Delete or deactivate the workflow instead.');
            }

            $step->delete();
            $this->resequence($workflow, $workflow->steps()->pluck('id')->all());
        });
    }

    /**
     * @param  list<int>  $orderedStepIds  every step of the workflow, in the new order
     */
    public function reorderSteps(ApprovalWorkflow $workflow, array $orderedStepIds): void
    {
        DB::connection('tenant')->transaction(function () use ($workflow, $orderedStepIds): void {
            $locked = $this->lockWorkflow($workflow);
            $this->assertEditable($locked);
            $current = $locked->steps()->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
            $given = array_map('intval', $orderedStepIds);

            if ($current !== collect($given)->sort()->values()->all() || count(array_unique($given)) !== count($given)) {
                throw ApiException::unprocessable('invalid_step_order', 'List every step of this workflow exactly once.');
            }

            $this->resequence($locked, $given);
        });
    }

    public function deactivateWorkflow(ApprovalWorkflow $workflow): ApprovalWorkflow
    {
        $workflow->forceFill(['is_active' => false])->save();

        return $workflow;
    }

    /**
     * Only a workflow that never ran is deleted (§60.2); otherwise deactivate.
     */
    public function deleteWorkflow(ApprovalWorkflow $workflow): void
    {
        DB::connection('tenant')->transaction(function () use ($workflow): void {
            $locked = $this->lockWorkflow($workflow);

            if ($locked->requests()->exists()) {
                throw ApiException::conflict('workflow_in_use', 'This workflow has approval requests. Deactivate it instead.');
            }

            $locked->delete();
        });
    }

    /**
     * The first active workflow, by sort_order, whose conditions match the
     * record; null when the engine or the module is not enabled (§60.3
     * step 1).
     */
    public function findMatchingWorkflow(string $moduleKey, Model $record): ?ApprovalWorkflow
    {
        $definition = self::registry()[$moduleKey] ?? null;
        $tenant = tenant();

        if ($definition === null || ! $tenant instanceof Tenant || ! $this->enabled($tenant, 'approval_workflows')
            || ($definition['feature'] !== 'core' && ! $this->enabled($tenant, $definition['feature']))) {
            return null;
        }

        $workflows = ApprovalWorkflow::query()->where('module_key', $moduleKey)->where('is_active', true)
            ->whereHas('steps')->orderBy('sort_order')->orderBy('id')->get();

        if ($workflows->isEmpty()) {
            return null;
        }

        /** @var Approvable $service */
        $service = app($definition['service']);
        $facts = $service->approvalFacts($record);

        return $workflows->first(static fn (ApprovalWorkflow $w): bool => self::matches((array) $w->trigger_conditions, $facts));
    }

    /**
     * @param  array<string, mixed>  $conditions
     * @param  array<string, string|int>  $facts
     */
    public static function matches(array $conditions, array $facts): bool
    {
        if (isset($conditions['min_amount']) && Money::cmp(Money::normalize((string) ($facts['amount'] ?? '0')), Money::normalize((string) $conditions['min_amount'])) < 0) {
            return false;
        }

        return ! (isset($conditions['min_days']) && (float) ($facts['days'] ?? 0) < (float) $conditions['min_days']);
    }

    /**
     * @param  array<string, mixed>  $stepData
     */
    private function insertStep(ApprovalWorkflow $workflow, array $stepData): ApprovalStep
    {
        $validated = validator($stepData, [
            'name' => ['required', 'string', 'max:120'],
            'approval_mode' => ['sometimes', Rule::in([ApprovalStep::ANY_ONE, ApprovalStep::ALL])],
            'approvers' => ['required', 'array', 'min:1', 'max:50'],
            'approvers.*.approver_type' => ['required', Rule::in([ApprovalStepApprover::ROLE, ApprovalStepApprover::USER])],
            'approvers.*.role_id' => ['required_if:approvers.*.approver_type,role', 'prohibited_unless:approvers.*.approver_type,role', 'nullable', 'integer',
                Rule::exists('tenant.roles', 'id')->where('guard_name', RoleService::GUARD)],
            'approvers.*.user_id' => ['required_if:approvers.*.approver_type,user', 'prohibited_unless:approvers.*.approver_type,user', 'nullable', 'integer',
                Rule::exists('tenant.users', 'id')->whereNull('deleted_at')],
        ])->validate();

        $step = new ApprovalStep(['name' => $validated['name'], 'approval_mode' => $validated['approval_mode'] ?? ApprovalStep::ANY_ONE]);
        $step->forceFill([
            'approval_workflow_id' => $workflow->id,
            'step_order' => (int) $workflow->steps()->max('step_order') + 1,
        ])->save();

        foreach ($validated['approvers'] as $approver) {
            $step->approvers()->create([
                'approver_type' => $approver['approver_type'],
                'role_id' => $approver['approver_type'] === ApprovalStepApprover::ROLE ? $approver['role_id'] : null,
                'user_id' => $approver['approver_type'] === ApprovalStepApprover::USER ? $approver['user_id'] : null,
            ]);
        }

        return $step->load('approvers.role:id,name', 'approvers.user:id,name');
    }

    /**
     * @param  list<int>  $orderedIds
     */
    private function resequence(ApprovalWorkflow $workflow, array $orderedIds): void
    {
        // Two passes around unique(workflow, step_order).
        $offset = 1000;

        foreach ($orderedIds as $i => $id) {
            ApprovalStep::query()->whereKey($id)->where('approval_workflow_id', $workflow->id)->update(['step_order' => $offset + $i + 1]);
        }

        foreach ($orderedIds as $i => $id) {
            ApprovalStep::query()->whereKey($id)->where('approval_workflow_id', $workflow->id)->update(['step_order' => $i + 1]);
        }
    }

    private function assertEditable(ApprovalWorkflow $workflow): void
    {
        if ($workflow->requests()->where('status', ApprovalRequest::PENDING)->exists()) {
            throw ApiException::conflict('workflow_has_pending_requests', 'Steps cannot change while approval requests are pending on this workflow.');
        }
    }

    private function lockWorkflow(ApprovalWorkflow $workflow): ApprovalWorkflow
    {
        /** @var ApprovalWorkflow */
        return ApprovalWorkflow::query()->lockForUpdate()->findOrFail($workflow->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateWorkflow(array $data, ?ApprovalWorkflow $existing): array
    {
        $moduleKey = (string) ($existing->module_key ?? ($data['module_key'] ?? ''));

        $validated = validator($data, [
            'name' => [$existing === null ? 'required' : 'sometimes', 'string', 'max:160'],
            'module_key' => $existing === null ? ['required', Rule::in(array_keys(self::registry()))] : ['prohibited'],
            'trigger_conditions' => ['sometimes', 'nullable', 'array'],
            'trigger_conditions.min_amount' => ['sometimes', 'numeric', 'min:0'],
            'trigger_conditions.min_days' => ['sometimes', 'numeric', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ], ['module_key.prohibited' => 'A workflow\'s module cannot change. Create a new workflow instead.'])->validate();

        // Only the conditions the module supports (A-51).
        $allowed = self::registry()[$moduleKey]['conditions'] ?? [];
        $unknown = array_diff(array_keys((array) ($validated['trigger_conditions'] ?? [])), $allowed);

        if ($unknown !== []) {
            throw ApiException::unprocessable('unsupported_trigger_condition', 'This module supports '.($allowed === [] ? 'no trigger conditions' : implode(', ', $allowed)).'.', ['unsupported' => array_values($unknown)]);
        }

        if (($validated['trigger_conditions'] ?? null) === []) {
            $validated['trigger_conditions'] = null;
        }

        unset($validated['is_active']);

        return $validated;
    }

    private function enabled(Tenant $tenant, string $feature): bool
    {
        return $this->features->state($tenant, $feature) === ModuleState::Enabled;
    }
}
