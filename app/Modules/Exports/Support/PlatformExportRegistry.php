<?php

declare(strict_types=1);

namespace App\Modules\Exports\Support;

use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliatePayout;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Platform export types (D-134): tenants, subscriptions, payments,
 * affiliates and payouts, each needing its list's platform permission.
 * Payout details, passwords and gateway credentials are never exported.
 */
final class PlatformExportRegistry
{
    /** @var array<string, ExportDefinition> */
    private array $definitions = [];

    public function __construct()
    {
        $range = ['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from']];
        $at = static fn ($value): ?string => $value?->toIso8601String();

        $this->register(new ExportDefinition(
            type: 'tenants',
            label: 'Tenants',
            rules: [...$range, 'status' => ['sometimes', 'string', 'max:32']],
            columns: ['id' => 'ID', 'name' => 'Store', 'slug' => 'Slug', 'owner_name' => 'Owner', 'email' => 'Email', 'status' => 'Status',
                'default_currency' => 'Currency', 'timezone' => 'Timezone', 'created_at' => 'Created', 'suspended_at' => 'Suspended', 'closed_at' => 'Closed'],
            rows: static fn (array $p): iterable => Tenant::query()
                ->when(isset($p['status']), static fn ($q) => $q->where('status', $p['status']))
                ->when(isset($p['from']), static fn ($q) => $q->where('created_at', '>=', $p['from']))
                ->when(isset($p['to']), static fn ($q) => $q->where('created_at', '<=', $p['to']))->orderBy('created_at')->lazy(1000)
                ->map(static fn (Tenant $t): array => [
                    'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'owner_name' => $t->owner_name, 'email' => $t->email,
                    'status' => $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status, 'default_currency' => $t->default_currency,
                    'timezone' => $t->timezone, 'created_at' => $at($t->created_at), 'suspended_at' => $at($t->suspended_at), 'closed_at' => $at($t->closed_at),
                ]),
            permission: 'tenants.view',
        ));

        $this->register(new ExportDefinition(
            type: 'subscriptions',
            label: 'Subscriptions',
            rules: [...$range, 'status' => ['sometimes', 'string', 'max:32']],
            columns: ['id' => 'ID', 'tenant' => 'Tenant', 'plan' => 'Plan', 'status' => 'Status', 'billing_interval' => 'Interval', 'currency_code' => 'Currency',
                'gateway' => 'Gateway', 'starts_at' => 'Starts', 'trial_ends_at' => 'Trial ends', 'renews_at' => 'Renews', 'cancelled_at' => 'Cancelled'],
            rows: static fn (array $p): iterable => Subscription::query()->with(['tenant:id,name', 'plan:id,name'])
                ->when(isset($p['status']), static fn ($q) => $q->where('status', $p['status']))
                ->when(isset($p['from']), static fn ($q) => $q->where('starts_at', '>=', $p['from']))
                ->when(isset($p['to']), static fn ($q) => $q->where('starts_at', '<=', $p['to']))->lazyById(1000)
                ->map(static fn (Subscription $s): array => [
                    'id' => $s->id, 'tenant' => $s->tenant?->name, 'plan' => $s->plan?->name, 'status' => $s->status instanceof \BackedEnum ? $s->status->value : (string) $s->status,
                    'billing_interval' => $s->billing_interval, 'currency_code' => $s->currency_code, 'gateway' => $s->gateway, 'starts_at' => $at($s->starts_at),
                    'trial_ends_at' => $at($s->trial_ends_at), 'renews_at' => $at($s->renews_at), 'cancelled_at' => $at($s->cancelled_at),
                ]),
            permission: 'subscriptions.view',
        ));

        $this->register(new ExportDefinition(
            type: 'payment_transactions',
            label: 'Platform payments',
            rules: [...$range, 'status' => ['sometimes', Rule::in(['pending', 'successful', 'failed'])], 'type' => ['sometimes', Rule::in(['charge', 'authorization', 'refund', 'chargeback'])]],
            columns: ['reference' => 'Reference', 'tenant' => 'Tenant', 'type' => 'Type', 'status' => 'Status', 'provider' => 'Provider', 'mode' => 'Mode',
                'amount' => 'Amount', 'fee' => 'Fee', 'currency_code' => 'Currency', 'paid_at' => 'Paid at', 'created_at' => 'Created'],
            rows: static fn (array $p): iterable => PaymentTransaction::query()->with('tenant:id,name')
                ->when(isset($p['status']), static fn ($q) => $q->where('status', $p['status']))
                ->when(isset($p['type']), static fn ($q) => $q->where('type', $p['type']))
                ->when(isset($p['from']), static fn ($q) => $q->where('created_at', '>=', $p['from']))
                ->when(isset($p['to']), static fn ($q) => $q->where('created_at', '<=', $p['to']))->lazyById(1000)
                ->map(static fn (PaymentTransaction $x): array => [
                    'reference' => $x->reference, 'tenant' => $x->tenant?->name, 'type' => $x->type, 'status' => $x->status, 'provider' => $x->provider,
                    'mode' => $x->mode, 'amount' => (string) $x->amount, 'fee' => $x->fee === null ? null : (string) $x->fee, 'currency_code' => $x->currency_code,
                    'paid_at' => $at($x->paid_at), 'created_at' => $at($x->created_at),
                ]),
            permission: 'payment-transactions.view',
        ));

        $this->register(new ExportDefinition(
            type: 'affiliates',
            label: 'Affiliates',
            rules: [...$range, 'status' => ['sometimes', 'string', 'max:32']],
            columns: ['public_id' => 'ID', 'name' => 'Name', 'email' => 'Email', 'company_name' => 'Company', 'status' => 'Status', 'referral_code' => 'Referral code',
                'commission_rate' => 'Commission rate override', 'approved_at' => 'Approved', 'created_at' => 'Applied'],
            rows: static fn (array $p): iterable => Affiliate::query()
                ->when(isset($p['status']), static fn ($q) => $q->where('status', $p['status']))
                ->when(isset($p['from']), static fn ($q) => $q->where('created_at', '>=', $p['from']))
                ->when(isset($p['to']), static fn ($q) => $q->where('created_at', '<=', $p['to']))->lazyById(1000)
                ->map(static fn (Affiliate $a): array => [
                    'public_id' => $a->public_id, 'name' => $a->name, 'email' => $a->email, 'company_name' => $a->company_name, 'status' => $a->status,
                    'referral_code' => $a->referral_code, 'commission_rate' => $a->commission_rate, 'approved_at' => $at($a->approved_at), 'created_at' => $at($a->created_at),
                ]),
            permission: 'affiliates.view',
        ));

        $this->register(new ExportDefinition(
            type: 'affiliate_payouts',
            label: 'Affiliate payouts',
            rules: [...$range, 'status' => ['sometimes', Rule::in(['pending', 'paid', 'failed', 'cancelled'])]],
            columns: ['reference' => 'Reference', 'affiliate' => 'Affiliate', 'amount' => 'Amount', 'currency_code' => 'Currency', 'commission_count' => 'Commissions',
                'period_start' => 'Period start', 'period_end' => 'Period end', 'status' => 'Status', 'payout_method' => 'Method', 'external_reference' => 'External reference', 'paid_at' => 'Paid at'],
            rows: static fn (array $p): iterable => AffiliatePayout::query()->with('affiliate:id,name')
                ->when(isset($p['status']), static fn ($q) => $q->where('status', $p['status']))
                ->when(isset($p['from']), static fn ($q) => $q->where('created_at', '>=', $p['from']))
                ->when(isset($p['to']), static fn ($q) => $q->where('created_at', '<=', $p['to']))->lazyById(1000)
                ->map(static fn (AffiliatePayout $x): array => [
                    'reference' => $x->reference, 'affiliate' => $x->affiliate?->name, 'amount' => (string) $x->amount, 'currency_code' => $x->currency_code,
                    'commission_count' => $x->commission_count, 'period_start' => $x->period_start->toDateString(), 'period_end' => $x->period_end->toDateString(),
                    'status' => $x->status, 'payout_method' => $x->payout_method, 'external_reference' => $x->external_reference, 'paid_at' => $at($x->paid_at),
                ]),
            permission: 'affiliate-payouts.view',
        ));
    }

    public function register(ExportDefinition $definition): void
    {
        $this->definitions[$definition->type] = $definition;
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function get(string $type): ExportDefinition
    {
        return $this->definitions[$type] ?? throw new InvalidArgumentException("Unknown platform export type [{$type}].");
    }
}
