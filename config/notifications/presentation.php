<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Email presentation per notification key (layout: mail.notification)
|--------------------------------------------------------------------------
|
| How the email layout dresses a notification. Wording stays in the
| editable templates (landlord.php / tenant.php and the database); this map
| only picks the header tone and label, the large highlighted value, the
| call-to-action button and the inbox preview line. Values name template
| variables; they are resolved when the notification is dispatched.
|
|   tone             default | success | warning | danger
|   eyebrow          small label above the title
|   highlight        variable shown large in the header (a code, an amount)
|   highlight_label  label above the highlight
|   action           variable holding the button URL (http/https only)
|   action_text      button text
|   preheader        inbox preview; may use {{variables}}
|
| A key without an entry gets the plain header (subject only). Nothing
| here is required for a notification to be sent.
|
*/

return [

    // ---- Landlord ------------------------------------------------------------

    'tenant.registration_verification' => ['eyebrow' => 'Verification', 'highlight' => 'code', 'highlight_label' => 'Verification code',
        'action' => 'verification_url', 'action_text' => 'Verify email', 'preheader' => 'Your verification code is {{code}}'],
    'tenant.provisioning_complete' => ['eyebrow' => 'Welcome', 'action' => 'admin_url', 'action_text' => 'Open your dashboard', 'preheader' => 'Your store is ready'],
    'tenant.legal_reacceptance_required' => ['tone' => 'warning', 'eyebrow' => 'Action needed'],
    'tenant.custom_domain_misconfigured' => ['tone' => 'warning', 'eyebrow' => 'Action needed'],

    'platform.provisioning_failed' => ['tone' => 'danger', 'eyebrow' => 'Alert'],
    'platform.webhook_failures' => ['tone' => 'danger', 'eyebrow' => 'Alert'],
    'platform.billing_payment_failed_alert' => ['tone' => 'danger', 'eyebrow' => 'Billing', 'highlight' => 'amount', 'highlight_label' => 'Failed charges'],
    'platform.tenant_export_ready' => ['eyebrow' => 'Export', 'action' => 'download_url', 'action_text' => 'Download export'],
    'platform.contact_submission_received' => ['eyebrow' => 'Contact form'],
    'platform.onboarding_paused' => ['tone' => 'warning', 'eyebrow' => 'Onboarding'],
    'platform.billing_mode_changed' => ['tone' => 'warning', 'eyebrow' => 'Billing'],

    'subscription.trial_ending_soon' => ['tone' => 'warning', 'eyebrow' => 'Subscription'],
    'subscription.payment_succeeded' => ['tone' => 'success', 'eyebrow' => 'Payment received', 'highlight' => 'amount', 'highlight_label' => 'Payment received',
        'preheader' => 'We received your payment of {{amount}}'],
    'subscription.payment_failed' => ['tone' => 'danger', 'eyebrow' => 'Action needed', 'preheader' => 'We could not charge {{amount}}'],
    'subscription.renewing_soon' => ['eyebrow' => 'Subscription'],
    'subscription.renewed' => ['tone' => 'success', 'eyebrow' => 'Subscription', 'highlight' => 'amount', 'highlight_label' => 'Amount charged'],
    'subscription.cancelled' => ['tone' => 'warning', 'eyebrow' => 'Subscription'],
    'subscription.plan_upgraded' => ['tone' => 'success', 'eyebrow' => 'Subscription'],
    'subscription.plan_downgraded' => ['tone' => 'warning', 'eyebrow' => 'Subscription'],
    'subscription.plan_limit_approaching' => ['tone' => 'warning', 'eyebrow' => 'Usage'],
    'subscription.plan_limit_reached' => ['tone' => 'danger', 'eyebrow' => 'Usage'],

    'platform_user.invited' => ['eyebrow' => 'Invitation', 'action' => 'set_password_url', 'action_text' => 'Set your password'],
    'platform_user.password_reset' => ['eyebrow' => 'Security', 'action' => 'reset_url', 'action_text' => 'Reset password'],
    'platform_user.email_verification' => ['eyebrow' => 'Verification', 'action' => 'verification_url', 'action_text' => 'Verify email'],

    'affiliate.email_verification' => ['eyebrow' => 'Verification', 'action' => 'verification_url', 'action_text' => 'Verify email'],
    'affiliate.password_reset' => ['eyebrow' => 'Security', 'action' => 'reset_url', 'action_text' => 'Reset password'],
    'affiliate.approved' => ['tone' => 'success', 'eyebrow' => 'Affiliate programme'],
    'affiliate.suspended' => ['tone' => 'danger', 'eyebrow' => 'Affiliate programme'],
    'affiliate.commission_created' => ['tone' => 'success', 'eyebrow' => 'Commission', 'highlight' => 'amount', 'highlight_label' => 'New commission'],
    'affiliate.commission_approved' => ['tone' => 'success', 'eyebrow' => 'Commission', 'highlight' => 'amount', 'highlight_label' => 'Commission approved'],
    'affiliate.commission_rejected' => ['tone' => 'warning', 'eyebrow' => 'Commission'],
    'affiliate.commission_reversed' => ['tone' => 'warning', 'eyebrow' => 'Commission'],
    'affiliate.payout_paid' => ['tone' => 'success', 'eyebrow' => 'Payout', 'highlight' => 'amount', 'highlight_label' => 'Payout sent'],
    'affiliate.payout_failed' => ['tone' => 'danger', 'eyebrow' => 'Payout'],

    // ---- Tenant (store) ------------------------------------------------------

    'customer.password_reset' => ['eyebrow' => 'Security', 'action' => 'reset_url', 'action_text' => 'Reset password'],
    'customer.email_verification' => ['eyebrow' => 'Verification', 'action' => 'verification_url', 'action_text' => 'Verify email'],
    'staff.password_reset' => ['eyebrow' => 'Security', 'action' => 'reset_url', 'action_text' => 'Reset password'],
    'seller.password_reset' => ['eyebrow' => 'Security', 'action' => 'reset_url', 'action_text' => 'Reset password'],

    'order.confirmed' => ['tone' => 'success', 'eyebrow' => 'Order confirmed', 'highlight' => 'order_total', 'highlight_label' => 'Order total',
        'preheader' => 'Thank you for your order {{order_number}}'],
    'order.payment_failed' => ['tone' => 'danger', 'eyebrow' => 'Payment failed', 'action' => 'order_url', 'action_text' => 'Try again'],
    'order.refunded' => ['tone' => 'success', 'eyebrow' => 'Refund', 'highlight' => 'amount', 'highlight_label' => 'Refunded'],
    'order.new_order_received' => ['tone' => 'success', 'eyebrow' => 'New order', 'highlight' => 'order_total', 'highlight_label' => 'Order total'],
    'order.payment_failed_staff_alert' => ['tone' => 'danger', 'eyebrow' => 'Payment failed'],
    'order.payment_needs_review' => ['tone' => 'warning', 'eyebrow' => 'Review needed'],
    'order.overpaid' => ['tone' => 'warning', 'eyebrow' => 'Review needed'],
    'order.refund_needs_review' => ['tone' => 'warning', 'eyebrow' => 'Review needed'],
    'order.payment_disputed' => ['tone' => 'danger', 'eyebrow' => 'Dispute', 'highlight' => 'amount', 'highlight_label' => 'Disputed'],
    'order.cancelled' => ['tone' => 'warning', 'eyebrow' => 'Order cancelled'],

    'return.refunded' => ['tone' => 'success', 'eyebrow' => 'Refund', 'highlight' => 'amount', 'highlight_label' => 'Refunded'],
    'return.rejected' => ['tone' => 'warning', 'eyebrow' => 'Return'],

    'account.data_export_ready' => ['eyebrow' => 'Your data', 'action' => 'download_url', 'action_text' => 'Download your data'],
    'export.ready' => ['eyebrow' => 'Export', 'action' => 'download_url', 'action_text' => 'Download export'],

    'gift_card.issued' => ['tone' => 'success', 'eyebrow' => 'Gift card', 'highlight' => 'amount', 'highlight_label' => 'Gift card value'],
    'sales_quotation.sent' => ['eyebrow' => 'Quotation', 'highlight' => 'total', 'highlight_label' => 'Quotation total', 'action' => 'quotation_url', 'action_text' => 'View quotation'],
    'quotation_request.sent' => ['eyebrow' => 'Quotation request', 'action' => 'request_url', 'action_text' => 'View request'],
    'product_subscription.payment_failed' => ['tone' => 'danger', 'eyebrow' => 'Subscription', 'action' => 'account_url', 'action_text' => 'Update payment'],
    'back_in_stock.available' => ['tone' => 'success', 'eyebrow' => 'Back in stock', 'action' => 'product_url', 'action_text' => 'Shop now'],
    'wishlist_item.back_in_stock' => ['tone' => 'success', 'eyebrow' => 'Back in stock', 'action' => 'product_url', 'action_text' => 'Shop now'],
    'wishlist_item.price_drop' => ['tone' => 'success', 'eyebrow' => 'Price drop', 'action' => 'product_url', 'action_text' => 'Shop now'],
    'coupon.expiring_soon' => ['tone' => 'warning', 'eyebrow' => 'Coupon', 'highlight' => 'coupon_code', 'highlight_label' => 'Your coupon'],
    'supplier.payment_due' => ['tone' => 'warning', 'eyebrow' => 'Payment due', 'highlight' => 'amount', 'highlight_label' => 'Amount due'],

    'accounting.posting_failed' => ['tone' => 'danger', 'eyebrow' => 'Accounting'],
    'approval.step_pending' => ['eyebrow' => 'Approval needed'],
    'approval.request_approved' => ['tone' => 'success', 'eyebrow' => 'Approved'],
    'approval.request_rejected' => ['tone' => 'danger', 'eyebrow' => 'Rejected'],

];
