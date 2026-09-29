<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\Hr\Models\HrDocumentType;
use App\Modules\Hr\Models\HrEmployee;
use App\Modules\Hr\Models\HrEmployeeDocument;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Media\StorageQuota;
use App\Shared\Media\UploadRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Employees and their documents (spec §58.1, §58.2, §58.8). A linked
 * employee takes name and email from the staff user; a standalone one
 * keeps its own. max_employees counts active employees.
 */
final readonly class HrEmployeeService
{
    public const string ENTITY = 'employee';

    public function __construct(
        private PlanLimitService $limits,
        private CustomFieldService $customFields,
        private StorageQuota $quota,
    ) {}

    public static function countActive(): int
    {
        return HrEmployee::query()->where('status', HrEmployee::ACTIVE)->count();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createEmployee(array $data): HrEmployee
    {
        [$validated, $custom] = $this->validate($data, null);

        return DB::connection('tenant')->transaction(function () use ($validated, $custom): HrEmployee {
            $this->assertWithinLimit();

            $employee = new HrEmployee;
            $employee->forceFill([...$validated, 'status' => HrEmployee::ACTIVE])->save();
            $this->customFields->save($employee, self::ENTITY, $custom);

            return $employee;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateEmployee(HrEmployee $employee, array $data): HrEmployee
    {
        [$validated, $custom] = $this->validate($data, $employee);

        return DB::connection('tenant')->transaction(function () use ($employee, $validated, $custom): HrEmployee {
            $employee->forceFill($validated)->save();
            $this->customFields->save($employee, self::ENTITY, $custom);

            return $employee;
        });
    }

    public function deactivateEmployee(HrEmployee $employee, ?string $terminationDate = null): HrEmployee
    {
        Validator::make(['termination_date' => $terminationDate], ['termination_date' => ['nullable', 'date_format:Y-m-d']])->validate();

        if ($employee->status === HrEmployee::INACTIVE) {
            throw ApiException::invalidTransition($employee->status, HrEmployee::INACTIVE);
        }

        $employee->forceFill(['status' => HrEmployee::INACTIVE, 'termination_date' => $terminationDate ?? today()->toDateString()])->save();

        return $employee;
    }

    public function reactivateEmployee(HrEmployee $employee): HrEmployee
    {
        return DB::connection('tenant')->transaction(function () use ($employee): HrEmployee {
            /** @var HrEmployee $locked */
            $locked = HrEmployee::query()->lockForUpdate()->findOrFail($employee->id);

            if ($locked->status === HrEmployee::ACTIVE) {
                throw ApiException::invalidTransition($locked->status, HrEmployee::ACTIVE);
            }

            $this->assertWithinLimit();
            $locked->forceFill(['status' => HrEmployee::ACTIVE, 'termination_date' => null])->save();

            return $locked;
        });
    }

    /**
     * Soft delete; the payroll, leave and attendance history stays. The
     * user link is released so the user can be linked again.
     */
    public function deleteEmployee(HrEmployee $employee): void
    {
        DB::connection('tenant')->transaction(static function () use ($employee): void {
            $employee->forceFill(['user_id' => null, 'status' => HrEmployee::INACTIVE, 'termination_date' => $employee->termination_date ?? today()])->save();
            $employee->delete();
        });
    }

    /**
     * Linking drops the standalone name and email: they come from the user (§58.1).
     */
    public function linkToUser(HrEmployee $employee, User $user): HrEmployee
    {
        if (HrEmployee::query()->where('user_id', $user->id)->whereKeyNot($employee->id)->exists()) {
            throw ApiException::unprocessable('user_already_linked', 'This user is already linked to another employee.');
        }

        $employee->forceFill(['user_id' => $user->id, 'first_name' => null, 'last_name' => null, 'email' => null])->save();

        return $employee;
    }

    /**
     * The employee becomes standalone and keeps the user's name and email.
     */
    public function unlinkFromUser(HrEmployee $employee): HrEmployee
    {
        if ($employee->user_id === null) {
            throw ApiException::unprocessable('employee_not_linked', 'This employee is not linked to a user.');
        }

        $employee->loadMissing('user');
        [$first, $last] = array_pad(explode(' ', (string) $employee->user?->name, 2), 2, '');
        $employee->forceFill(['user_id' => null, 'first_name' => $first !== '' ? $first : 'Employee', 'last_name' => $last !== '' ? $last : (string) $employee->id,
            'email' => $employee->user?->email])->save();

        return $employee;
    }

    /**
     * @param  array{status?: string, department_id?: int, employment_type?: string, search?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, HrEmployee>
     */
    public function listEmployees(array $filters = []): LengthAwarePaginator
    {
        $search = isset($filters['search']) ? '%'.addcslashes((string) $filters['search'], '%_\\').'%' : null;

        return HrEmployee::query()->with(['user:id,name,email', 'department:id,name'])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['department_id']), static fn ($q) => $q->where('department_id', $filters['department_id']))
            ->when(isset($filters['employment_type']), static fn ($q) => $q->where('employment_type', $filters['employment_type']))
            ->when($search !== null, static fn ($q) => $q->where(static fn ($w) => $w->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search)
                ->orWhere('employee_number', 'like', $search)->orWhereHas('user', static fn ($u) => $u->where('name', 'like', $search))))
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function attachDocument(HrEmployee $employee, UploadedFile $file, HrDocumentType $type, ?string $expiryDate = null, ?string $notes = null): HrEmployeeDocument
    {
        Validator::make(['file' => $file, 'expiry_date' => $expiryDate, 'notes' => $notes], [
            'file' => UploadRules::document(),
            'expiry_date' => [$type->requires_expiry_date ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:255'],
        ])->validate();

        if (! $type->is_active) {
            throw ApiException::unprocessable('document_type_inactive', 'This document type is no longer in use.');
        }

        $this->quota->assertAllows($file);

        return DB::connection('tenant')->transaction(static function () use ($employee, $file, $type, $expiryDate, $notes): HrEmployeeDocument {
            $document = new HrEmployeeDocument;
            $document->forceFill(['employee_id' => $employee->id, 'document_type_id' => $type->id, 'expiry_date' => $expiryDate, 'notes' => $notes])->save();
            $document->addMedia($file)
                ->usingFileName(Str::uuid().'.'.$file->guessExtension())
                ->usingName(mb_substr($file->getClientOriginalName(), 0, 200))
                ->toMediaCollection('file');

            return $document;
        });
    }

    /**
     * @return Collection<int, HrEmployeeDocument>
     */
    public function listDocuments(HrEmployee $employee): Collection
    {
        return HrEmployeeDocument::query()->with(['documentType:id,name', 'media'])->where('employee_id', $employee->id)->orderBy('id')->get();
    }

    public function deleteDocument(HrEmployeeDocument $document): void
    {
        DB::connection('tenant')->transaction(static function () use ($document): void {
            $document->clearMediaCollection('file');
            $document->delete();
        });
    }

    /**
     * Documents of active employees whose expiry falls in the next days
     * (or has passed), soonest first.
     *
     * @return Collection<int, HrEmployeeDocument>
     */
    public function getExpiringDocuments(int $withinDays = 30): Collection
    {
        return HrEmployeeDocument::query()->with(['documentType:id,name', 'employee.user:id,name,email'])
            ->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', today()->addDays($withinDays))
            ->whereHas('employee', static fn ($q) => $q->where('status', HrEmployee::ACTIVE))
            ->orderBy('expiry_date')->get();
    }

    private function assertWithinLimit(): void
    {
        $tenant = tenant();
        $limit = $tenant instanceof Tenant ? $this->limits->getLimit($tenant, 'max_employees') : null;

        if ($limit !== null && self::countActive() >= $limit) {
            throw ApiException::forbidden('limit_reached', 'Upgrade your plan to add more employees.', ['limit' => 'max_employees', 'limit_value' => $limit]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validate(array $data, ?HrEmployee $existing): array
    {
        $creating = $existing === null;
        $linked = array_key_exists('user_id', $data) ? $data['user_id'] !== null : ($existing?->user_id !== null);
        $standaloneRule = $linked ? 'prohibited' : ($creating ? 'required' : 'sometimes');

        $validator = Validator::make($data, [
            'user_id' => [$creating ? 'sometimes' : 'prohibited', 'nullable', 'integer', Rule::exists('tenant.users', 'id'), Rule::unique('tenant.hr_employees', 'user_id')],
            'department_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.hr_departments', 'id')],
            'employee_number' => ['sometimes', 'nullable', 'string', 'max:32', Rule::unique('tenant.hr_employees', 'employee_number')->ignore($existing?->id)],
            'first_name' => [$standaloneRule, 'string', 'max:100'],
            'last_name' => [$standaloneRule, 'string', 'max:100'],
            'email' => [$linked ? 'prohibited' : 'sometimes', 'nullable', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'employment_type' => [$creating ? 'required' : 'sometimes', Rule::in(HrEmployee::EMPLOYMENT_TYPES)],
            'hire_date' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'custom_fields' => ['sometimes', 'array'],
        ], ['user_id.prohibited' => 'Use link-user and unlink-user to change the linked user.']);

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

        if (isset($validated['email'])) {
            $validated['email'] = strtolower((string) $validated['email']);
        }

        return [$validated, $custom];
    }
}
