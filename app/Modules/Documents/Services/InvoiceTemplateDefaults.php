<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Documents\Models\BarcodeSetting;
use App\Modules\Documents\Models\InvoiceTemplate;

/**
 * The provisioning defaults of §43 (spec §9.4): the A4 "Default" invoice
 * template (prefix INV-, sequential) and a default sticker layout.
 * Insert-only: an existing default is never overwritten.
 */
final class InvoiceTemplateDefaults
{
    public static function create(): InvoiceTemplate
    {
        $existing = InvoiceTemplate::query()->where('is_default', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        $template = new InvoiceTemplate(['name' => 'Default', 'invoice_type' => 'a4', 'prefix' => 'INV-', 'numbering_type' => 'sequential', 'start_number' => 1]);
        $template->forceFill(['is_default' => true])->save();

        return $template;
    }

    public static function seed(): void
    {
        self::create();

        if (! BarcodeSetting::query()->exists()) {
            $setting = new BarcodeSetting([
                'name' => 'A4 sheet, 3 x 7', 'label_layout' => 'continuous', 'top_margin_inches' => '0.500', 'left_margin_inches' => '0.250',
                'sticker_width_inches' => '2.600', 'sticker_height_inches' => '1.400', 'paper_width_inches' => '8.270', 'paper_height_inches' => '11.690',
                'stickers_per_row' => 3, 'row_distance_inches' => '0.000', 'column_distance_inches' => '0.100', 'stickers_per_sheet' => 21,
            ]);
            $setting->forceFill(['is_default' => true])->save();
        }
    }
}
