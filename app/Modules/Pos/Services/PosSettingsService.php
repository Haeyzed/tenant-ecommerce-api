<?php

declare(strict_types=1);

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosSettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * POS settings (spec §51.7), a singleton row created with the defaults on
 * first read.
 */
final class PosSettingsService
{
    public function getSettings(): PosSettings
    {
        $settings = PosSettings::query()->first();

        if ($settings === null) {
            $settings = new PosSettings;
            $settings->forceFill(['enabled_payment_methods' => ['cash']])->save();
            $settings->refresh();
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(array $data): PosSettings
    {
        $validated = Validator::make($data, [
            'default_warehouse_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.warehouses', 'id')],
            'default_customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.customers', 'id')->whereNull('deleted_at')],
            'default_cashier_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tenant.users', 'id')->where('is_active', true)],
            'products_per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'touchscreen_keyboard_enabled' => ['sometimes', 'boolean'],
            'table_management_enabled' => ['sometimes', 'boolean'],
            'send_sms_after_sale' => ['sometimes', 'boolean'],
            'cash_register_enabled' => ['sometimes', 'boolean'],
            'print_receipt_by_default' => ['sometimes', 'boolean'],
            'play_sound_on_sale' => ['sometimes', 'boolean'],
            'enabled_payment_methods' => ['sometimes', 'array', 'min:1'],
            'enabled_payment_methods.*' => ['string', 'distinct', Rule::in(PosSettings::METHODS)],
        ])->validate();

        if (isset($validated['enabled_payment_methods'])) {
            $validated['enabled_payment_methods'] = array_values($validated['enabled_payment_methods']);
        }

        $settings = $this->getSettings();
        $settings->forceFill($validated)->save();

        return $settings;
    }

    public function methodEnabled(string $method): bool
    {
        return in_array($method, $this->getSettings()->enabled_payment_methods, true);
    }
}
