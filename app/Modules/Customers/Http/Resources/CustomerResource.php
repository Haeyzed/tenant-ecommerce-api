<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\CustomFields\Services\CustomFieldService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
final class CustomerResource extends JsonResource
{
    /** Admin routes see every active field; the customer only public ones (§23.5). */
    public string $context = CustomFieldService::ADMIN;

    public function forCustomer(): self
    {
        $this->context = CustomFieldService::PUBLIC;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'custom_fields' => app(CustomFieldService::class)->valuesFor($this->resource, CustomerService::ENTITY, $this->context),
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'email_verified' => $this->email_verified_at !== null,
            'is_active' => $this->is_active,
            'has_login' => $this->password !== null,
            'customer_group' => $this->whenLoaded('group', fn (): ?array => $this->group === null ? null : ['id' => $this->group->id, 'name' => $this->group->name]),
            'addresses' => AddressResource::collection($this->whenLoaded('addresses')),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
