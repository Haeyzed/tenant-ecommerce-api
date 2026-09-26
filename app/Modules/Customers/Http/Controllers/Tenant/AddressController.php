<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Customers\Http\Resources\AddressResource;
use App\Modules\Customers\Models\Address;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in customer's addresses. Another customer's address is never
 * found (404), whatever the id.
 */
final class AddressController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(Request $request): JsonResponse
    {
        return APIResponse::success(AddressResource::collection($this->customer($request)->addresses()->orderByDesc('is_default')->orderBy('id')->get()));
    }

    public function store(Request $request): JsonResponse
    {
        return APIResponse::created(new AddressResource($this->customers->addAddress($this->customer($request), $request->all())), 'Address added');
    }

    public function update(Request $request, int $address): JsonResponse
    {
        return APIResponse::success(new AddressResource($this->customers->updateAddress($this->own($request, $address), $request->all())), 'Address updated');
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $this->customers->deleteAddress($this->own($request, $address));

        return APIResponse::noContent('Address deleted');
    }

    public function setDefault(Request $request, int $address): JsonResponse
    {
        return APIResponse::success(new AddressResource($this->customers->setDefaultAddress($this->customer($request), $this->own($request, $address))), 'Default address set');
    }

    private function own(Request $request, int $id): Address
    {
        /** @var Address */
        return $this->customer($request)->addresses()->whereKey($id)->firstOrFail();
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer */
        return $request->user();
    }
}
