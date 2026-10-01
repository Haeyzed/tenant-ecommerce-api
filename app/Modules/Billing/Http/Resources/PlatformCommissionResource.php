<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\PlatformCommission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformCommission
 */
final class PlatformCommissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            /** @var array{id: string, name: string, slug: string}|null */
            'tenant' => $this->whenLoaded('tenant', fn (): ?array => $this->tenant === null ? null : ['id' => (string) $this->tenant->id, 'name' => (string) $this->tenant->name, 'slug' => (string) $this->tenant->slug]),
            'kind' => $this->kind,
            'order_number' => $this->order_number,
            'base_amount' => (string) $this->base_amount,
            'rate' => (string) $this->rate,
            'amount' => (string) $this->amount,
            'currency_code' => $this->currency_code,
            'status' => $this->status,
            'payment_transaction_id' => $this->payment_transaction_id,
            'waived_reason' => $this->waived_reason,
            'collected_at' => $this->collected_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
