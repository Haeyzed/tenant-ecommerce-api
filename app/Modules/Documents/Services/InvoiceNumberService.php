<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Models\InvoiceTemplate;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Invoice numbers from the default template (spec §43.1), assigned on
 * confirmation inside the caller's transaction. Test orders take
 * "TEST-{order_number}" and never consume the real sequence, so live
 * invoice numbers stay gap-free (§40.8).
 */
final class InvoiceNumberService
{
    public function next(Order $order): string
    {
        if ($order->is_test) {
            return 'TEST-'.$order->order_number;
        }

        return DB::connection('tenant')->transaction(static function (): string {
            /** @var InvoiceTemplate|null $template */
            $template = InvoiceTemplate::query()->where('is_default', true)->lockForUpdate()->first() ?? InvoiceTemplateDefaults::create();
            $prefix = (string) $template->prefix;

            if ($template->numbering_type === 'random') {
                do {
                    $number = $prefix.str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
                } while (Order::withTrashed()->where('invoice_number', $number)->exists());

                return $number;
            }

            $next = max($template->start_number, ($template->last_number ?? 0) + 1);
            $template->forceFill(['last_number' => $next])->saveQuietly();

            return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
        });
    }
}
