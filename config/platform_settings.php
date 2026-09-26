<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Platform settings registry (spec §13.2)
|--------------------------------------------------------------------------
|
| Every platform_settings key is declared here with its group, type, default,
| validation rules and whether it is public (returned by GET
| /api/platform/config). PlatformSettingsService rejects any other key.
|
| reason: changing the key requires a reason in the activity log.
| own_route: the key is changed only through its own route, never through
| PATCH /api/admin/platform-settings/{group}.
|
*/

$s = static fn (string $type, mixed $default, array $rules, bool $public = false, bool $reason = false, bool $ownRoute = false): array => [
    'type' => $type,
    'default' => $default,
    'rules' => $rules,
    'public' => $public,
    'reason' => $reason,
    'own_route' => $ownRoute,
];

return [

    'general' => [
        'platform_name' => $s('string', env('APP_NAME', 'Platform'), ['required', 'string', 'max:120'], true),
        'platform_logo_media_id' => $s('int', null, ['nullable', 'integer'], true),
        'platform_favicon_media_id' => $s('int', null, ['nullable', 'integer'], true),
        'support_email' => $s('string', null, ['nullable', 'email:rfc', 'max:255'], true),
        'support_phone' => $s('string', null, ['nullable', 'string', 'max:40'], true),
        'legal_entity_name' => $s('string', null, ['nullable', 'string', 'max:255'], true),
        'legal_entity_address' => $s('string', null, ['nullable', 'string', 'max:500'], true),
        'social_links' => $s('json', null, ['nullable', 'array:facebook,instagram,x,linkedin,youtube,tiktok'], true),
        'seo_title_template' => $s('string', '{page} - {platform_name}', ['required', 'string', 'max:120'], true),
        'seo_default_description' => $s('string', null, ['nullable', 'string', 'max:320'], true),
        'seo_share_image_media_id' => $s('int', null, ['nullable', 'integer'], true),
        'analytics_measurement_id' => $s('string', null, ['nullable', 'string', 'regex:/^(G|UA)-[A-Z0-9\-]{4,20}$/'], true),
    ],

    'localization' => [
        'default_timezone' => $s('string', 'UTC', ['required', 'timezone:all'], true),
        'default_locale' => $s('string', 'en', ['required', 'string', 'max:10'], true),
        'default_date_format' => $s('string', 'YYYY-MM-DD', ['required', 'in:DD/MM/YYYY,MM/DD/YYYY,YYYY-MM-DD,DD MMM YYYY,"MMM DD, YYYY"'], true),
        'default_time_format' => $s('string', '24h', ['required', 'in:24h,12h'], true),
        'default_currency' => $s('string', 'USD', ['required', 'string', 'size:3'], true),
    ],

    'tenant_onboarding' => [
        'tenant_registration_enabled' => $s('bool', true, ['required', 'boolean'], true, true),
        'tenant_provisioning_enabled' => $s('bool', true, ['required', 'boolean'], false, true),
        'registration_verification_hours' => $s('int', 24, ['required', 'integer', 'min:1', 'max:168']),
        'unpaid_registration_expiry_days' => $s('int', 7, ['required', 'integer', 'min:1', 'max:90']),
    ],

    'trials' => [
        'trials_enabled' => $s('bool', true, ['required', 'boolean'], true, true),
        'default_trial_days' => $s('int', 7, ['required', 'integer', 'min:0', 'max:90'], true, true),
    ],

    'billing' => [
        'proration_mode' => $s('string', 'next_renewal', ['required', 'in:immediate,next_renewal']),
        'past_due_grace_days' => $s('int', 7, ['required', 'integer', 'min:0', 'max:90']),
        'past_due_restriction' => $s('string', 'read_only', ['required', 'in:none,read_only,blocked']),
        'close_tenants_on_cancellation' => $s('bool', false, ['required', 'boolean']),
        'reporting_exchange_rates' => $s('json', null, ['nullable', 'array']),
    ],

    'payments' => [
        'billing_payment_mode' => $s('string', env('APP_ENV') === 'production' ? 'live' : 'test', ['required', 'in:test,live'], false, true, true),
        'commission_enabled' => $s('bool', false, ['required', 'boolean']),
        'default_commission_rate' => $s('decimal', '0', ['required', 'numeric', 'min:0', 'max:100']),
    ],

    'commerce' => [
        'guest_checkout_allowed_platform_wide' => $s('bool', true, ['required', 'boolean']),
        'max_pos_registers_per_warehouse' => $s('int', null, ['nullable', 'integer', 'min:1']),
        'default_return_window_days' => $s('int', null, ['nullable', 'integer', 'min:0', 'max:365']),
    ],

    'affiliates' => [
        'affiliate_program_enabled' => $s('bool', false, ['required', 'boolean'], true, true),
        'affiliate_default_commission_rate' => $s('decimal', '20', ['required', 'numeric', 'min:0', 'max:100'], true, true),
        'affiliate_cookie_days' => $s('int', 30, ['required', 'integer', 'min:1', 'max:365'], true, true),
        'affiliate_conversion_window_days' => $s('int', 180, ['nullable', 'integer', 'min:1', 'max:1095'], false, true),
        'affiliate_commission_hold_days' => $s('int', 30, ['required', 'integer', 'min:0', 'max:180'], false, true),
        'affiliate_commission_approval' => $s('string', 'manual', ['required', 'in:manual,automatic'], false, true),
        'affiliate_minimum_payout' => $s('json', ['USD' => 50], ['required', 'array'], true, true),
        'affiliate_payout_schedule' => $s('string', 'monthly', ['required', 'in:monthly,manual'], true, true),
        'affiliate_payout_day' => $s('int', 1, ['required', 'integer', 'min:1', 'max:28'], true, true),
        'affiliate_coupon_attribution_enabled' => $s('bool', true, ['required', 'boolean'], false, true),
    ],

    'custom_domains' => [
        'custom_domains_enabled' => $s('bool', false, ['required', 'boolean'], false, true),
        'custom_domain_cname_target' => $s('string', null, ['nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/i'], false, true),
        'custom_domain_ipv4_addresses' => $s('json', null, ['nullable', 'array'], false, true),
        'custom_domain_ipv6_addresses' => $s('json', null, ['nullable', 'array'], false, true),
        'custom_domain_verification_prefix' => $s('string', '_platform-verify', ['required', 'string', 'max:63', 'regex:/^_?[a-z0-9-]+$/'], false, true),
        'custom_domain_verification_window_hours' => $s('int', 72, ['required', 'integer', 'min:1', 'max:720'], false, true),
    ],

    'email' => [
        'platform_email_enabled' => $s('bool', true, ['required', 'boolean']),
    ],

    'system' => [
        'maintenance_mode' => $s('bool', false, ['required', 'boolean'], false, true),
        'default_maintenance_behavior' => $s('string', 'hard_block', ['required', 'in:hard_block,read_only']),
        'tenant_retention_days' => $s('int', 90, ['required', 'integer', 'min:1', 'max:3650']),
        'purged_backup_retention_days' => $s('int', 30, ['required', 'integer', 'min:1', 'max:3650']),
    ],

];
