<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Services\CustomerGroupService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerGroupController extends Controller
{
    public function __construct(private readonly CustomerGroupService $groups) {}

    public function index(): JsonResponse
    {
        return APIResponse::success($this->groups->listGroups()->map(fn (CustomerGroup $g): array => $this->present($g))->values());
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created($this->present($this->groups->createGroup($request->all())), 'Customer group created');
    }

    public function update(Request $request, CustomerGroup $group): JsonResponse
    {
        return APIResponse::success($this->present($this->groups->updateGroup($group, $request->all())), 'Customer group updated');
    }

    public function destroy(CustomerGroup $group): JsonResponse
    {
        $this->groups->deleteGroup($group);

        return APIResponse::noContent('Customer group deleted');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CustomerGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'is_default' => $group->is_default,
            'customers_count' => $group->customers_count ?? null,
        ];
    }
}
