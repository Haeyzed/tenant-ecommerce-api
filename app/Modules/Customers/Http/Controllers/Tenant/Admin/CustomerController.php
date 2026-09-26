<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Customers\Services\CustomerGroupService;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Customer administration (spec §26.5).
 */
final class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'customer_group_id' => ['sometimes', 'nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (array_key_exists('is_active', $filters)) {
            $filters['is_active'] = $request->boolean('is_active');
        }

        return APIResponse::success(CustomerResource::collection($this->customers->listCustomers($filters)));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(new CustomerResource($this->customers->createCustomer($request->all(), $this->staff($request))->load('group')), 'Customer created');
    }

    public function show(Customer $customer): JsonResponse
    {
        return APIResponse::success(new CustomerResource($this->customers->getCustomer($customer)));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        return APIResponse::success(new CustomerResource($this->customers->updateCustomer($customer, $request->all(), $this->staff($request))->load('group')), 'Customer updated');
    }

    public function deactivate(Request $request, Customer $customer): JsonResponse
    {
        return APIResponse::success(new CustomerResource($this->customers->deactivateCustomer($customer, $this->staff($request))), 'Customer deactivated');
    }

    /**
     * Anonymises the customer (erasure request, §26.4). Irreversible.
     */
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->customers->deleteCustomer($customer, $this->staff($request));

        return APIResponse::success(null, 'Customer anonymised');
    }

    public function export(Request $request, Customer $customer): JsonResponse
    {
        $this->customers->exportPersonalData($customer, $this->staff($request));

        return APIResponse::accepted(null, 'The export is being prepared; the customer will receive a link by email');
    }

    public function assignGroup(Request $request, Customer $customer, CustomerGroupService $groups): JsonResponse
    {
        $id = $request->validate(['customer_group_id' => ['required', 'integer', Rule::exists('tenant.customer_groups', 'id')]])['customer_group_id'];
        $customer = $groups->assignCustomerToGroup($customer, CustomerGroup::query()->findOrFail($id));

        return APIResponse::success(new CustomerResource($customer->load('group')), 'Customer group assigned');
    }

    private function staff(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
