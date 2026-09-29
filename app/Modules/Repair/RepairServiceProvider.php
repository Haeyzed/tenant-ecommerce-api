<?php

declare(strict_types=1);

namespace App\Modules\Repair;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Repair\Models\RepairJob;
use App\Modules\Repair\Services\RepairJobService;
use Illuminate\Support\ServiceProvider;

/**
 * Repair jobs in the custom-field registry (§23.1) and personal data
 * (§26.4): exported, and on erasure the contact snapshots are cleared
 * (the jobs stay for the workshop's records).
 */
final class RepairServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(CustomFieldEntityRegistry::class, static function (CustomFieldEntityRegistry $registry): void {
            $registry->register('repair_job', RepairJob::class, 'repair-jobs', 'repair');
        });

        $this->app->afterResolving(CustomerPrivacyRegistry::class, static function (CustomerPrivacyRegistry $privacy): void {
            $privacy->registerEraser('repair_jobs', static fn (Customer $customer) => RepairJob::query()->where('customer_id', $customer->id)
                ->update(['customer_name' => 'Erased customer', 'customer_phone' => null, 'updated_at' => now()]));
            $privacy->registerSection('repair_jobs', static fn (Customer $customer): iterable => RepairJob::query()
                ->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(static fn (RepairJob $j): array => [
                    'job_number' => RepairJobService::number($j),
                    'item' => $j->item_description,
                    'status' => $j->status,
                    'diagnosis' => $j->diagnosis_notes,
                    'estimated_cost' => $j->estimated_cost === null ? null : (string) $j->estimated_cost,
                    'received_at' => $j->received_at->toIso8601String(),
                ]));
        });
    }
}
