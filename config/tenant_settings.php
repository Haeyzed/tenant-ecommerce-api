<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenant business settings registry (spec §13.4)
|--------------------------------------------------------------------------
|
| The authoritative key list: a key not declared here MUST NOT be read.
| default: a literal, or "tenant:{attribute}" / "platform:{key}" resolved at
| read time. own_route: changed only through its own route (never through
| PATCH /api/admin/settings). readonly: returned but never written.
| requires_feature: the value may only be set while that module is enabled.
|
*/

$s = static fn (string $type, mixed $default, array $rules, array $extra = []): array => array_merge([
    'type' => $type,
    'default' => $default,
    'rules' => $rules,
    'own_route' => false,
    'requires_feature' => null,
], $extra);

$dateFormats = 'DD/MM/YYYY,MM/DD/YYYY,YYYY-MM-DD,DD MMM YYYY,"MMM DD, YYYY"';

return [

    // Business identity
    'store_name' => $s('string', 'tenant:name', ['required', 'string', 'max:120']),
    'system_title' => $s('string', null, ['nullable', 'string', 'max:120']),
    'store_logo_media_id' => $s('int', null, ['nullable', 'integer']),
    'favicon_media_id' => $s('int', null, ['nullable', 'integer']),
    'store_contact_email' => $s('string', 'tenant:email', ['required', 'email:rfc', 'max:255']),
    'store_contact_phone' => $s('string', null, ['nullable', 'string', 'max:40']),
    'store_address' => $s('json', null, ['nullable', 'array:line1,line2,city_id,state_id,country_id,postal_code']),
    'business_hours' => $s('json', null, ['nullable', 'array', 'max:7']),
    'vat_registration_number' => $s('string', null, ['nullable', 'string', 'max:40']),

    // Onboarding
    'onboarding_dismissed' => $s('bool', false, ['required', 'boolean']),
    'tax_setup_confirmed' => $s('bool', false, ['required', 'boolean']),

    // Localisation and formatting
    'default_currency' => $s('string', 'tenant:default_currency', ['required', 'string', 'size:3'], ['own_route' => true]),
    'timezone' => $s('string', 'platform:default_timezone', ['required', 'timezone:all']),
    'locale' => $s('string', 'platform:default_locale', ['required', 'string', 'max:10']),
    'date_format' => $s('string', null, ['nullable', 'in:'.$dateFormats]),
    'time_format' => $s('string', null, ['nullable', 'in:24h,12h']),
    'rtl_enabled' => $s('bool', false, ['required', 'boolean']),
    'default_currency_position' => $s('string', 'before', ['required', 'in:before,after']),
    'decimal_digits' => $s('int', 2, ['required', 'integer', 'min:0', 'max:4']),

    // Email and notifications
    'email_provider' => $s('string', 'platform', ['required', 'in:platform,custom']),
    'custom_mail_settings' => $s('encrypted_json', null, ['nullable', 'array:mail_driver,host,port,username,password,encryption,from_address,from_name'], ['requires_feature' => 'custom_email']),
    'notifications_enabled' => $s('bool', true, ['required', 'boolean']),
    'order_notification_email' => $s('string', null, ['nullable', 'email:rfc', 'max:255']),
    'low_stock_notification_recipients' => $s('json', null, ['nullable', 'array', 'max:20']),

    // Orders, checkout and documents
    'default_order_status' => $s('string', 'pending', ['required', 'in:pending,processing']),
    'unpaid_order_expiry_minutes' => $s('int', 60, ['required', 'integer', 'min:15', 'max:10080']),
    'prices_include_tax' => $s('bool', false, ['required', 'boolean']),
    'tax_shipping' => $s('bool', false, ['required', 'boolean']),
    'guest_checkout_enabled' => $s('bool', true, ['required', 'boolean']),
    'invoice_format' => $s('string', 'standard', ['required', 'in:standard,gst']),
    'india_gst_enabled' => $s('bool', false, ['required', 'boolean']),
    'saudi_zatca_enabled' => $s('bool', false, ['required', 'boolean']),
    'packing_slip_enabled' => $s('bool', true, ['required', 'boolean']),

    // Inventory and catalogue
    'low_stock_threshold' => $s('int', 5, ['required', 'integer', 'min:0']),
    'expiring_product_alert_days' => $s('int', null, ['nullable', 'integer', 'min:1', 'max:365']),
    'show_product_stock_on_purchase_list' => $s('bool', true, ['required', 'boolean']),
    'show_product_stock_on_sales_list' => $s('bool', true, ['required', 'boolean']),
    'product_view_count_public' => $s('bool', true, ['required', 'boolean']),
    'review_moderation_required' => $s('bool', true, ['required', 'boolean']),
    'reviews_require_verified_purchase' => $s('bool', false, ['required', 'boolean']),
    'qa_moderation_required' => $s('bool', true, ['required', 'boolean']),

    // Returns
    'requires_physical_return_by_default' => $s('bool', true, ['required', 'boolean']),
    'return_window_days' => $s('int', null, ['nullable', 'integer', 'min:0', 'max:365']),

    // Staff access
    'staff_data_access_scope' => $s('string', 'all', ['required', 'in:all,own,warehouse']),

    // Payments
    'payment_mode' => $s('string', 'test', ['required', 'in:test,live'], ['own_route' => true]),

    // Module defaults
    'installments_enabled' => $s('bool', false, ['required', 'boolean']),
    'installments_minimum_order_amount' => $s('decimal', null, ['nullable', 'numeric', 'min:0']),
    'installments_fulfillment_policy' => $s('string', 'on_full_payment', ['required', 'in:on_full_payment,on_first_payment']),
    'installments_default_after_overdue_count' => $s('int', 2, ['required', 'integer', 'min:1', 'max:24']),
    'default_purchase_order_currency' => $s('string', null, ['nullable', 'string', 'size:3']),
    'pos_cash_variance_threshold' => $s('decimal', null, ['nullable', 'numeric', 'min:0']),
    'allow_quotation_without_stock' => $s('bool', true, ['required', 'boolean']),
    'default_seller_commission_rate' => $s('decimal', '0', ['required', 'numeric', 'min:0', 'max:100']),
    'seller_product_approval_required' => $s('bool', true, ['required', 'boolean']),
    'seller_payout_hold_days' => $s('int', null, ['nullable', 'integer', 'min:0', 'max:365']),
    'booking_requires_prepayment' => $s('bool', false, ['required', 'boolean']),
    'default_sales_agent_commission_rate' => $s('decimal', '0', ['required', 'numeric', 'min:0', 'max:100']),
    'product_subscription_max_failed_renewals' => $s('int', 3, ['required', 'integer', 'min:1', 'max:12']),

];
