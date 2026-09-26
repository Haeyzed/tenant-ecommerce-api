<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Http\Resources\CustomerResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in customer's own account (spec §26.5).
 */
final class AccountController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function show(Request $request): JsonResponse
    {
        return APIResponse::success((new CustomerResource($this->customers->getCustomer($this->customer($request))))->forCustomer());
    }

    public function update(Request $request): JsonResponse
    {
        $customer = $this->customers->updateCustomer($this->customer($request), $request->only(['name', 'email', 'phone', 'custom_fields']));

        if ($customer->wasChanged('email') && $customer->email !== null) {
            $customer->sendEmailVerificationNotification();
        }

        return APIResponse::success((new CustomerResource($customer->load('group', 'addresses')))->forCustomer(), 'Account updated');
    }

    /**
     * Deletes (anonymises) the account; requires the current password.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'string', 'max:255']]);
        $customer = $this->customer($request);

        if ($customer->password === null || ! Hash::check((string) $request->input('current_password'), $customer->password)) {
            throw ValidationException::withMessages(['current_password' => [__('auth.password')]]);
        }

        $this->customers->deleteCustomer($customer, $customer);

        return APIResponse::success(null, 'Your account was deleted');
    }

    public function export(Request $request): JsonResponse
    {
        $this->customers->exportPersonalData($this->customer($request));

        return APIResponse::accepted(null, 'Your data export is being prepared; we will email you a link');
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user();
    }
}
