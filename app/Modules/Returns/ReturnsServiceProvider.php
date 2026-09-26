<?php

declare(strict_types=1);

namespace App\Modules\Returns;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\Returns\Models\OrderReturn;
use Illuminate\Support\ServiceProvider;

/**
 * Registers returns with customer privacy (§26.4): erasure removes the
 * customer's notes and photos; the export lists their returns.
 */
final class ReturnsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(CustomerPrivacyRegistry::class, static function (CustomerPrivacyRegistry $privacy): void {
            $privacy->registerEraser('returns', static function (Customer $customer): void {
                OrderReturn::query()->where('customer_id', $customer->id)->get()->each(static function (OrderReturn $return): void {
                    $return->clearMediaCollection('photos');
                    $return->forceFill(['customer_note' => null])->saveQuietly();
                });
            });

            $privacy->registerSection('returns', static fn (Customer $customer): iterable => OrderReturn::query()->with(['order:id,order_number', 'reason:id,label'])
                ->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(static fn (OrderReturn $r): array => [
                    'return_number' => $r->return_number,
                    'order_number' => $r->order?->order_number,
                    'reason' => $r->reason?->label,
                    'status' => $r->status,
                    'note' => $r->customer_note,
                    'requested_at' => $r->requested_at->toIso8601String(),
                ]));
        });
    }
}
