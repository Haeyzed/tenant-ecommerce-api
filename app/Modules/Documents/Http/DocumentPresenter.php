<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http;

use App\Modules\Documents\Models\BarcodeSetting;
use App\Modules\Documents\Models\InvoiceTemplate;
use App\Modules\Documents\Models\ReceiptPrinter;

/**
 * Response shapes of the Documents module (spec §43).
 */
final class DocumentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function template(InvoiceTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'invoice_type' => $t->invoice_type,
            'is_default' => $t->is_default,
            'prefix' => $t->prefix,
            'numbering_type' => $t->numbering_type,
            'start_number' => $t->start_number,
            'last_number' => $t->last_number,
            'header_text' => $t->header_text,
            'footer_text' => $t->footer_text,
            'payment_notes' => $t->payment_notes,
            'sales_notes' => $t->sales_notes,
            'logo_media_id' => $t->logo_media_id,
            'logo_height' => $t->logo_height,
            'logo_width' => $t->logo_width,
            'primary_color' => $t->primary_color,
            'date_format' => $t->date_format,
            ...array_combine(InvoiceTemplate::FLAGS, array_map(static fn (string $flag): bool => (bool) $t->getAttribute($flag), InvoiceTemplate::FLAGS)),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function barcodeSetting(BarcodeSetting $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'description' => $s->description,
            'label_layout' => $s->label_layout,
            'is_default' => $s->is_default,
            ...array_combine(BarcodeSetting::DIMENSIONS, array_map(static fn (string $d): string => (string) $s->getAttribute($d), BarcodeSetting::DIMENSIONS)),
            'stickers_per_row' => $s->stickers_per_row,
            'stickers_per_sheet' => $s->stickers_per_sheet,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function printer(ReceiptPrinter $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'warehouse' => $p->relationLoaded('warehouse') ? ['id' => $p->warehouse_id, 'name' => $p->warehouse->name] : ['id' => $p->warehouse_id],
            'connection_type' => $p->connection_type,
            'capability_profile' => $p->capability_profile,
            'characters_per_line' => $p->characters_per_line,
            'ip_address' => $p->ip_address,
            'port' => $p->port,
            'is_active' => $p->is_active,
        ];
    }
}
