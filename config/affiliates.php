<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Affiliate programme rules (spec §21A)
|--------------------------------------------------------------------------
|
| Business settings (rate, cookie days, hold, payouts) are platform
| settings in the "affiliates" group (§13.2). These are the fixed fraud
| thresholds and operating constants.
|
*/

return [

    // Referral codes: 4 to 20 characters [A-Z0-9]; generated ones use this length.
    'referral_code_length' => 8,

    // A rejected email may apply again after this many days (§21A.2).
    'reapply_after_days' => 90,

    // Changing payout details blocks automatic payout generation (§21A.2).
    'payout_details_hold_hours' => 72,

    // Clicks by one visitor on one affiliate within this window are one click (§21A.3).
    'click_dedupe_hours' => 24,

    // Clicks never linked to a referral are deleted after this many months (§21A.3).
    'click_retention_months' => 13,

    // Referral code → affiliate resolution cache (§ caching table).
    'code_cache_minutes' => 10,

    // Review flags (§21A.7).
    'velocity' => [
        'per_affiliate_24h' => 10,
        'per_ip_24h' => 3,
    ],
    'affiliate_login_ip_days' => 30,
    'rapid_refund' => [
        'window_days' => 90,
        'max_refunded_share_percent' => 30,
        // Too few conversions say nothing about a pattern.
        'min_conversions' => 3,
    ],

    // Owner and affiliate emails sharing one of these domains is not suspicious.
    'public_mail_domains' => [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'outlook.com', 'hotmail.com', 'hotmail.co.uk',
        'live.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'zoho.com',
        'gmx.com', 'gmx.de', 'mail.com', 'yandex.com', 'yandex.ru', 'qq.com', '163.com',
    ],

];
