<?php

declare(strict_types=1);

namespace App\Modules\Installments\Http;

use App\Modules\Installments\Models\InstallmentPayment;
use App\Modules\Installments\Models\InstallmentPlan;

final class InstallmentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function plan(InstallmentPlan $plan, bool $admin = false): array
    {
        return [
            'id' => $plan->id,
            'order_id' => $plan->order_id,
            'order' => $admin && $plan->relationLoaded('order') ? ['order_number' => $plan->order->order_number, 'customer_name' => $plan->order->customer_name, 'customer_email' => $plan->order->customer_email] : null,
            'total_amount' => (string) $plan->total_amount,
            'currency_code' => $plan->currency_code,
            'number_of_installments' => $plan->number_of_installments,
            'frequency' => $plan->frequency,
            'status' => $plan->status,
            'starts_at' => $plan->starts_at->toDateString(),
            'has_stored_payment_method' => $plan->authorization_token !== null,
            'consecutive_overdue_count' => $plan->consecutive_overdue_count,
            'payments' => $plan->payments->map(static fn (InstallmentPayment $p): array => [
                'id' => $p->id,
                'sequence' => $p->sequence,
                'amount_due' => (string) $p->amount_due,
                'amount_paid' => (string) $p->amount_paid,
                'due_date' => $p->due_date->toDateString(),
                'paid_at' => $p->paid_at?->toIso8601String(),
                'status' => $p->status,
            ])->values()->all(),
        ];
    }
}
