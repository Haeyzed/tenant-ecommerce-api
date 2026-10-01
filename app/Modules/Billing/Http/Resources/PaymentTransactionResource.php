<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Raw provider data (meta) is shown to platform users only.
 *
 * @mixin PaymentTransaction
 */
final class PaymentTransactionResource extends JsonResource
{
    public bool $includeProviderData = false;

    /** Set on the detail view of a refundable charge (null otherwise). */
    public ?string $refundableAmount = null;

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
            'subscription_id' => $this->subscription_id,
            'type' => $this->type,
            'mode' => $this->mode,
            'provider' => $this->provider,
            'reference' => $this->reference,
            'provider_reference' => $this->provider_reference,
            'amount' => (string) $this->amount,
            'currency_code' => $this->currency_code,
            'status' => $this->status,
            'refund_of_payment_transaction_id' => $this->refund_of_payment_transaction_id,
            'is_first_paid_charge' => $this->is_first_paid_charge,
            'line_items' => $this->line_items ?? [],
            'fee' => $this->fee !== null ? (string) $this->fee : null,
            'failure_reason' => $this->failure_reason,
            'reason' => $this->reason,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            /** @var array<string, mixed> */
            'meta' => $this->when($this->includeProviderData, fn (): array => (array) $this->meta),
            /** @var string|null */
            'refundable_amount' => $this->when($this->includeProviderData, fn (): ?string => $this->refundableAmount),
        ];
    }

    public function withProviderData(?string $refundableAmount = null): self
    {
        $this->includeProviderData = true;
        $this->refundableAmount = $refundableAmount;

        return $this;
    }
}
