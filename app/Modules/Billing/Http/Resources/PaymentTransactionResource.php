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

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
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
            'meta' => $this->when($this->includeProviderData, fn (): array => (array) $this->meta),
        ];
    }

    public function withProviderData(): self
    {
        $this->includeProviderData = true;

        return $this;
    }
}
