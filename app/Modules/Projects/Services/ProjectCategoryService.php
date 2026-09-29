<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectCategory;
use App\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Project categories (spec §63.3).
 */
final readonly class ProjectCategoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createCategory(array $data): ProjectCategory
    {
        $category = new ProjectCategory;
        $category->forceFill(['status' => 'active', ...$this->validate($data, true)])->save();

        return $category;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCategory(ProjectCategory $category, array $data): ProjectCategory
    {
        $category->forceFill($this->validate($data, false))->save();

        return $category;
    }

    /**
     * Blocked while projects use it: deactivate it instead.
     */
    public function deleteCategory(ProjectCategory $category): void
    {
        if (Project::query()->where('project_category_id', $category->id)->exists()) {
            throw ApiException::unprocessable('project_category_in_use', 'Projects use this category. Deactivate it instead.');
        }

        $category->delete();
    }

    /**
     * @param  array{status?: string}  $filters
     * @return Collection<int, ProjectCategory>
     */
    public function listCategories(array $filters = []): Collection
    {
        return ProjectCategory::query()->withCount('projects')
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating): array
    {
        return Validator::make($data, [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(ProjectCategory::STATUSES)],
        ])->validate();
    }
}
