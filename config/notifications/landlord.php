<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Landlord notification defaults (spec §17.4, §17.9)
|--------------------------------------------------------------------------
|
| One entry per landlord catalog key: default content, audience, channel
| matrix and whether the template is mandatory. Placeholders are written
| {{name}}; {{platform_name}} is always available. Seeding inserts missing
| rows only; resetToDefault() restores an entry.
|
| Landlord notifications to tenants use email and sms only (UD-10 interim).
|
*/

$n = static fn (array $audience, array $channels, string $subject, string $body, bool $mandatory = false): array => [
    'audience' => $audience,
    'channels' => $channels,
    'subject' => $subject,
    'body' => $body,
    'mandatory' => $mandatory,
];

$tenantEmail = ['email' => true, 'sms' => false];
$staffInbox = ['email' => true, 'database' => true];

return [
    // Registration and tenant lifecycle
    'tenant.registration_verification' => $n(['registrant'], ['email' => true],
        'Verify your email for {{platform_name}}',
        "Hello {{owner_name}},\n\nYour verification code is {{code}}. It expires in {{expires_in_hours}} hours.\n\nYou can also verify by opening this link: {{verification_url}}\n\nIf you did not sign up, ignore this email.",
        true),
    'tenant.provisioning_complete' => $n(['tenant'], $tenantEmail,
        'Your store {{tenant_name}} is ready',
        "Hello {{owner_name}},\n\nYour store {{tenant_name}} is ready. Sign in to your dashboard at {{admin_url}} with the email you registered.\n\nWelcome to {{platform_name}}."),
    'tenant.legal_reacceptance_required' => $n(['tenant'], $tenantEmail,
        'Please review the updated {{document_title}}',
        "Hello {{owner_name}},\n\nWe have published version {{document_version}} of the {{document_title}}. Please review and accept it from your dashboard to keep full access.",
        true),
    'tenant.custom_domain_misconfigured' => $n(['tenant'], $tenantEmail,
        'Action needed: {{domain}} is not reaching your store',
        "Hello {{owner_name}},\n\nThe domain {{domain}} has a problem: {{problem}}.\n\nPlease check its DNS records. Your store remains reachable at {{fallback_domain}}.",
        true),

    // Platform-internal
    'platform.contact_submission_received' => $n(['platform_user'], $staffInbox,
        'New contact submission from {{name}}',
        "{{name}} ({{email}}) wrote:\n\n{{message}}"),
    'platform.onboarding_paused' => $n(['platform_user'], $staffInbox,
        'Tenant onboarding paused',
        "The following onboarding settings were switched off: {{settings}}.\n\nReason: {{reason}}\n\nRemember to switch them back on when the reason has passed.",
        true),
    'platform.billing_mode_changed' => $n(['platform_user'], $staffInbox,
        'Platform billing mode changed to {{mode}}',
        "The platform billing mode was changed from {{previous_mode}} to {{mode}} by {{changed_by}}.\n\nReason: {{reason}}",
        true),
    'platform.provisioning_failed' => $n(['platform_user'], $staffInbox,
        'Provisioning failed for {{tenant_name}}',
        "Provisioning of tenant {{tenant_name}} ({{tenant_id}}) failed after all retries at step {{step}}.\n\nReview the tenant in the admin panel and retry provisioning.",
        true),
    'platform.webhook_failures' => $n(['platform_user'], $staffInbox,
        '{{provider}} webhooks are failing',
        "A {{provider}} webhook exhausted its retries. Event: {{event_type}}. Reference: {{reference}}.\n\nFurther failures for this provider are summarised at most once per hour.",
        true),
    'platform.tenant_export_ready' => $n(['platform_user'], $staffInbox,
        'Export of {{tenant_name}} is ready',
        "Hello {{name}},\n\nThe full export of {{tenant_name}} is ready: {{download_url}}\n\nThe link expires in {{expires_in_hours}} hours. It contains customer data: handle it according to the data-protection policy.",
        true),
    'platform.billing_payment_failed_alert' => $n(['platform_user'], $staffInbox,
        'Failed platform charges on {{date}}',
        "{{count}} live subscription charges failed on {{date}}, totalling {{amount}}.\n\nReview them in the billing dashboard."),

    // Subscription
    'subscription.trial_ending_soon' => $n(['tenant'], $tenantEmail,
        'Your trial ends on {{trial_ends_at}}',
        "Hello {{owner_name}},\n\nYour {{plan_name}} trial ends on {{trial_ends_at}}. Add a payment method from your billing page to keep your store running without interruption."),
    'subscription.payment_succeeded' => $n(['tenant'], $tenantEmail,
        'Payment received: {{amount}}',
        "Hello {{owner_name}},\n\nWe received your payment of {{amount}} for the {{plan_name}} plan. Reference: {{reference}}.\n\nThank you."),
    'subscription.payment_failed' => $n(['tenant'], $tenantEmail,
        'Payment failed for your {{plan_name}} subscription',
        "Hello {{owner_name}},\n\nWe could not charge {{amount}} for your {{plan_name}} subscription: {{failure_reason}}.\n\nPlease update your payment method from your billing page before {{grace_ends_at}} to avoid restrictions.",
        true),
    'subscription.renewing_soon' => $n(['tenant'], $tenantEmail,
        'Your subscription renews on {{renews_at}}',
        "Hello {{owner_name}},\n\nYour {{plan_name}} subscription renews on {{renews_at}} for {{amount}}."),
    'subscription.renewed' => $n(['tenant'], $tenantEmail,
        'Your subscription has been renewed',
        "Hello {{owner_name}},\n\nYour {{plan_name}} subscription has been renewed until {{renews_at}}. Amount charged: {{amount}}."),
    'subscription.cancelled' => $n(['tenant'], $tenantEmail,
        'Your subscription has been cancelled',
        "Hello {{owner_name}},\n\nYour {{plan_name}} subscription has been cancelled. Your store stays fully available until {{ends_at}}.\n\nYou can resubscribe from your billing page at any time.",
        true),
    'subscription.plan_upgraded' => $n(['tenant'], $tenantEmail,
        'You are now on {{plan_name}}',
        "Hello {{owner_name}},\n\nYour plan has changed from {{previous_plan_name}} to {{plan_name}}. The new features are available now."),
    'subscription.plan_downgraded' => $n(['tenant'], $tenantEmail,
        'Your plan changes to {{plan_name}} on {{effective_at}}',
        "Hello {{owner_name}},\n\nYour plan will change from {{previous_plan_name}} to {{plan_name}} on {{effective_at}}.\n\nModules that become read-only: {{locked_modules}}.\nLimits your current usage exceeds: {{exceeded_limits}}.\n\nNo data is deleted."),
    'subscription.plan_limit_approaching' => $n(['tenant'], $tenantEmail,
        'You have used {{percent}}% of your {{limit_label}}',
        "Hello {{owner_name}},\n\nYour store uses {{used}} of {{limit_value}} {{limit_label}} on the {{plan_name}} plan. Upgrade from your billing page for more room."),
    'subscription.plan_limit_reached' => $n(['tenant'], $tenantEmail,
        'Limit reached: {{limit_label}}',
        "Hello {{owner_name}},\n\nYour store has reached its limit of {{limit_value}} {{limit_label}}. Creating more is blocked until you upgrade or reduce usage."),

    // Module notices
    'module_notice.published' => $n(['tenant'], $tenantEmail,
        '{{notice_title}}',
        "Hello {{owner_name}},\n\n{{notice_message}}\n\nModule: {{module_name}}. From {{starts_at}} to {{ends_at}}."),

    // Platform users
    'platform_user.invited' => $n(['platform_user'], ['email' => true],
        'You have been invited to {{platform_name}}',
        "Hello {{name}},\n\n{{invited_by}} created an administrator account for you. Set your password here: {{set_password_url}}\n\nThe link expires in {{expires_in_minutes}} minutes.",
        true),
    'platform_user.password_reset' => $n(['platform_user'], ['email' => true],
        'Reset your {{platform_name}} password',
        "Hello {{name}},\n\nUse this link to reset your password: {{reset_url}}\n\nThe link expires in {{expires_in_minutes}} minutes. If you did not ask for a reset, ignore this email.",
        true),
    'platform_user.email_verification' => $n(['platform_user'], ['email' => true],
        'Verify your {{platform_name}} email address',
        "Hello {{name}},\n\nConfirm your email address by opening this link: {{verification_url}}",
        true),

    // Platform support
    'platform_support.reply_received' => $n(['tenant'], $tenantEmail,
        'New reply on "{{subject}}"',
        "Hello {{owner_name}},\n\nThe {{platform_name}} support team replied to your conversation \"{{subject}}\":\n\n{{message_excerpt}}"),
    'platform_support.message_received' => $n(['platform_user'], $staffInbox,
        'New support message from {{tenant_name}}',
        "{{tenant_name}} wrote in \"{{subject}}\":\n\n{{message_excerpt}}"),

    // Affiliate programme (§21A)
    'affiliate.email_verification' => $n(['affiliate'], ['email' => true],
        'Verify your email for the {{platform_name}} affiliate programme',
        "Hello {{name}},\n\nConfirm your email address by opening this link: {{verification_url}}",
        true),
    'affiliate.password_reset' => $n(['affiliate'], ['email' => true],
        'Reset your affiliate password',
        "Hello {{name}},\n\nUse this link to reset your password: {{reset_url}}\n\nThe link expires in {{expires_in_minutes}} minutes. If you did not ask for a reset, ignore this email.",
        true),
    'affiliate.application_received' => $n(['affiliate'], ['email' => true, 'database' => true],
        'We received your affiliate application',
        "Hello {{name}},\n\nThank you for applying to the {{platform_name}} affiliate programme. We will review your application and let you know."),
    'affiliate.application_submitted' => $n(['platform_user'], $staffInbox,
        'New affiliate application: {{affiliate_name}}',
        '{{affiliate_name}} ({{affiliate_email}}) applied to the affiliate programme. Review the application in the admin panel.'),
    'affiliate.approved' => $n(['affiliate'], ['email' => true, 'database' => true],
        'Your affiliate application is approved',
        "Hello {{name}},\n\nWelcome to the {{platform_name}} affiliate programme. Your referral link is {{referral_link}}."),
    'affiliate.rejected' => $n(['affiliate'], ['email' => true, 'database' => true],
        'Your affiliate application',
        "Hello {{name}},\n\nWe are unable to accept your affiliate application at this time.\n\n{{reason}}"),
    'affiliate.suspended' => $n(['affiliate'], ['email' => true, 'database' => true],
        'Your affiliate account is suspended',
        "Hello {{name}},\n\nYour affiliate account has been suspended. Reason: {{reason}}\n\nContact us if you believe this is a mistake.",
        true),
    'affiliate.payout_details_changed' => $n(['affiliate'], ['email' => true, 'database' => true],
        'Your payout details were changed',
        "Hello {{name}},\n\nThe payout details on your affiliate account were changed on {{changed_at}}. If you did not make this change, contact us immediately.",
        true),
    'affiliate.commission_created' => $n(['affiliate'], ['email' => true, 'database' => true],
        'New commission: {{amount}}',
        "Hello {{name}},\n\nA referral payment earned you a commission of {{amount}}. It becomes payable after the hold period ends on {{hold_until}}."),
    'affiliate.commission_approved' => $n(['affiliate'], ['email' => true, 'database' => true],
        'Commission approved: {{amount}}',
        "Hello {{name}},\n\nYour commission of {{amount}} has been approved and will be included in the next payout."),
    'affiliate.commission_rejected' => $n(['affiliate'], ['email' => true, 'database' => true],
        'Commission rejected: {{amount}}',
        "Hello {{name}},\n\nYour commission of {{amount}} was rejected. Reason: {{reason}}"),
    'affiliate.commission_reversed' => $n(['affiliate'], ['email' => true, 'database' => true],
        'Commission reversed: {{amount}}',
        "Hello {{name}},\n\nA commission of {{amount}} was reversed because the underlying payment was refunded or disputed."),
    'affiliate.payout_paid' => $n(['affiliate'], ['email' => true, 'database' => true],
        'Payout sent: {{amount}}',
        "Hello {{name}},\n\nWe sent your payout of {{amount}}. Reference: {{reference}}."),
    'affiliate.payout_failed' => $n(['affiliate'], ['email' => true, 'database' => true],
        'Payout failed: {{amount}}',
        "Hello {{name}},\n\nYour payout of {{amount}} failed: {{reason}}. Please check your payout details.",
        true),
    'affiliate.payouts_ready' => $n(['platform_user'], $staffInbox,
        '{{count}} affiliate payouts are ready',
        '{{count}} affiliate payouts totalling {{amount}} were generated and await payment.'),
    'affiliate.commissions_awaiting_approval' => $n(['platform_user'], $staffInbox,
        '{{count}} commissions await approval',
        '{{count}} affiliate commissions have passed their hold period and await manual approval.'),
    'affiliate.referral_flagged' => $n(['platform_user'], $staffInbox,
        'Affiliate referral flagged',
        'A referral by {{affiliate_name}} for tenant {{tenant_name}} was flagged: {{reason}}.'),
];
