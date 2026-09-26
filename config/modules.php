<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Module registry (spec §11.4)
|--------------------------------------------------------------------------
|
| The single registry of optional TENANT feature keys. Code, not data: keys
| are referenced by routes, middleware, jobs and permissions, so a key is
| added or removed only with a release. Landlord platform modules (affiliate
| marketing) are not registered here (spec §11.3).
|
| class: module | submodule | capability | vertical | integration
| activation: auto (enabled as soon as entitled) | manual (owner enables)
| read_when_inactive: admin GET routes stay open while disabled or locked
| wind_down: route names that stay usable while disabled or locked (§11.5)
|
*/

$module = static fn (
    string $name,
    string $class,
    string $section,
    string $codeModule,
    array $permissionGroups,
    array $requires = [],
    string $activation = 'manual',
    bool $readWhenInactive = true,
    array $customFieldEntities = [],
    ?string $lifecycle = null,
    array $windDown = [],
): array => [
    'name' => $name,
    'class' => $class,
    'section' => $section,
    'code_module' => $codeModule,
    'requires' => $requires,
    'activation' => $activation,
    'read_when_inactive' => $readWhenInactive,
    'permission_groups' => $permissionGroups,
    'custom_field_entities' => $customFieldEntities,
    'lifecycle' => $lifecycle,
    'wind_down' => $windDown,
];

return [

    'pos' => $module('Point of sale', 'module', '§51', 'Pos', ['pos'], windDown: ['tenant.admin.pos.sessions.close', 'tenant.admin.pos.sales.store']),
    'purchasing' => $module('Suppliers and purchasing', 'module', '§49', 'Purchasing', ['suppliers', 'supplier-payments', 'purchase-orders', 'quotation-requests', 'supplier-quotations', 'purchase-returns', 'purchase-return-reasons'], customFieldEntities: ['supplier', 'purchase_order'], windDown: ['tenant.admin.purchase-orders.receive', 'tenant.admin.suppliers.payments.store', 'tenant.admin.purchase-returns.store', 'tenant.admin.purchase-returns.approve', 'tenant.admin.purchase-returns.ship-back', 'tenant.admin.purchase-returns.refund']),
    'accounting' => $module('Accounting (general ledger)', 'module', '§57', 'Accounting', ['accounting']),
    'expenses' => $module('Expenses, income and billers', 'module', '§57.4', 'Expenses', ['billers', 'expense-categories', 'expenses', 'income-categories', 'income'], activation: 'auto', customFieldEntities: ['expense']),
    'hr' => $module('Human resources', 'module', '§58', 'Hr', ['hr'], customFieldEntities: ['employee']),
    'hr_payroll' => $module('Payroll', 'submodule', '§58.5', 'Hr', ['hr.payroll-runs', 'hr.payroll-items', 'hr.payroll-item-lines', 'hr.employees.salary', 'hr.employees.payslips', 'hr.employees.payroll-history'], requires: ['hr'], windDown: ['tenant.admin.hr.payroll-runs.mark-paid', 'tenant.admin.hr.payroll-items.mark-paid']),
    'hr_recruitment' => $module('Recruitment and careers', 'submodule', '§58.7', 'Hr', ['hr.job-postings', 'hr.applications'], requires: ['hr']),
    'marketplace' => $module('Sellers, seller groups, ledger and payouts', 'module', '§50', 'Marketplace', ['sellers', 'seller-groups', 'seller-products'], customFieldEntities: ['seller'], windDown: ['tenant.admin.sellers.payouts.store', 'tenant.admin.sellers.payouts.mark-paid']),
    'gift_cards' => $module('Gift cards', 'module', '§46', 'GiftCards', ['gift-cards'], windDown: ['tenant.storefront.cart.gift-card.store', 'tenant.storefront.cart.gift-card.destroy', 'tenant.public.gift-cards.balance']),
    'installments' => $module('Installment payments', 'capability', '§47', 'Installments', ['installment-plans'], windDown: ['tenant.storefront.installment-payments.pay']),
    'multi_currency' => $module('Multi-currency pricing', 'capability', '§48', 'Currency', ['currencies', 'products.prices']),
    'reward_points' => $module('Loyalty and reward points', 'module', '§54', 'RewardPoints', ['reward-points', 'customers.reward-points']),
    'sales_quotations' => $module('Sales quotations', 'module', '§53', 'SalesQuotations', ['sales-quotation-requests', 'sales-quotations'], customFieldEntities: ['sales_quotation']),
    'sales_agents' => $module('Sales agents and commissions', 'module', '§52', 'SalesAgents', ['sales-agents', 'sales-agent-commissions'], windDown: ['tenant.admin.sales-agent-commissions.mark-paid']),
    'product_subscriptions' => $module('Recurring product orders', 'module', '§55', 'ProductSubscriptions', ['product-subscriptions', 'products.subscription-plans'], windDown: ['tenant.customer.account.product-subscriptions.pause', 'tenant.customer.account.product-subscriptions.resume', 'tenant.customer.account.product-subscriptions.destroy']),
    'back_in_stock_alerts' => $module('Back-in-stock alerts', 'capability', '§56', 'BackInStock', ['products.back-in-stock-subscribers'], activation: 'auto'),
    'support' => $module('Customer support', 'module', '§59', 'Support', ['support']),
    'approval_workflows' => $module('Approval workflow engine', 'capability', '§60', 'Approvals', ['approval-workflows', 'approvals'], windDown: ['tenant.admin.approvals.approve', 'tenant.admin.approvals.reject']),
    'advanced_reporting' => $module('Advanced reports', 'capability', '§61', 'Reporting', ['reports'], activation: 'auto', readWhenInactive: false),
    'ai_assistant' => $module('AI business assistant', 'module', '§62', 'AiAssistant', ['ai-assistant'], readWhenInactive: false),
    'project_management' => $module('Projects and tasks', 'module', '§63', 'Projects', ['projects', 'project-categories'], customFieldEntities: ['project']),
    'content_marketing' => $module('Blog, FAQs and testimonials', 'capability', '§24.4', 'Cms', ['cms.blog-categories', 'cms.blog-posts', 'cms.tags', 'cms.faq-categories', 'cms.faqs', 'cms.testimonials'], activation: 'auto'),
    'custom_email' => $module("Tenant's own mail provider", 'capability', '§16.2', 'Messaging', ['settings.mail'], activation: 'auto'),
    'manufacturing' => $module('Bills of materials and work orders', 'vertical', '§64', 'Manufacturing', ['bill-of-materials', 'work-orders', 'products.bill-of-materials'], customFieldEntities: ['work_order'], windDown: ['tenant.admin.work-orders.complete', 'tenant.admin.work-orders.cancel']),
    'restaurant' => $module('Floors, tables, reservations, modifiers, kitchen display', 'vertical', '§65', 'Restaurant', ['restaurant', 'products.modifier-groups'], requires: ['pos'], windDown: ['tenant.admin.restaurant.table-orders.settle', 'tenant.admin.restaurant.table-orders.void']),
    'booking' => $module('Bookable services and appointments', 'vertical', '§66', 'Booking', ['booking-staff', 'bookings', 'products.booking-staff'], customFieldEntities: ['booking'], windDown: ['tenant.admin.bookings.cancel', 'tenant.admin.bookings.complete', 'tenant.customer.account.bookings.cancel']),
    'repair' => $module('Repair jobs', 'vertical', '§67', 'Repair', ['repair-jobs'], customFieldEntities: ['repair_job'], windDown: ['tenant.admin.repair-jobs.status']),
    'woocommerce' => $module('WooCommerce integration', 'integration', '§68', 'Integrations\\WooCommerce', ['woocommerce']),
    'social_commerce' => $module('Social commerce channels', 'integration', '§69', 'Integrations\\SocialCommerce', ['social-commerce']),
    'whatsapp' => $module('WhatsApp channel', 'integration', '§16.4', 'Messaging', ['whatsapp-settings']),

];
