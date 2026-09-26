<?php

declare(strict_types=1);

namespace App\Shared\Support;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliatePayout;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Models\PlatformCoupon;
use App\Modules\Billing\Models\PlatformPaymentGateway;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Cms\Models\CmsBanner;
use App\Modules\Cms\Models\CmsBlogPost;
use App\Modules\Cms\Models\CmsPage;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Cms\Models\ContactSubmission;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\CustomFields\Models\CustomFieldDefinition;
use App\Modules\Exports\Models\DataExport;
use App\Modules\Legal\Models\LegalDocument;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Messaging\Models\SmsGatewaySetting;
use App\Modules\Messaging\Models\WhatsAppSetting;
use App\Modules\ModuleNotices\Models\ModuleNotice;
use App\Modules\Payments\Models\TenantPaymentSetting;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Plans\Models\TenantFeature;
use App\Modules\Plans\Models\TenantLimitOverride;
use App\Modules\Plans\Models\TenantModule;
use App\Modules\PlatformSupport\Models\PlatformSupportConversation;
use App\Modules\PlatformSupport\Models\PlatformSupportMessageAttachment;
use App\Modules\Settings\Models\PlatformSetting;
use App\Modules\Settings\Models\StorefrontSetting;
use App\Modules\Settings\Models\TenantPlatformSetting;
use App\Modules\Shipping\Models\Driver;
use App\Modules\Tenancy\Models\DatabaseServer;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantRegistration;
use App\Modules\Users\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The enforced morph map (spec §5.1). Class names are never stored raw in
 * polymorphic columns. Every model used in a morph relation (media, audits,
 * activity log, tokens, notifications, roles) is registered here.
 */
final class MorphMap
{
    /**
     * @var array<string, class-string>
     */
    public const array MAP = [
        // Landlord
        'tenant' => Tenant::class,
        'domain' => Domain::class,
        'database_server' => DatabaseServer::class,
        'platform_user' => PlatformUser::class,
        'affiliate' => Affiliate::class,
        'affiliate_commission' => AffiliateCommission::class,
        'affiliate_payout' => AffiliatePayout::class,
        'affiliate_referral' => AffiliateReferral::class,
        'plan' => Plan::class,
        'plan_price' => PlanPrice::class,
        'tenant_feature' => TenantFeature::class,
        'tenant_limit_override' => TenantLimitOverride::class,
        'tenant_module' => TenantModule::class,
        'platform_setting' => PlatformSetting::class,
        'tenant_platform_setting' => TenantPlatformSetting::class,
        'subscription' => Subscription::class,
        'payment_transaction' => PaymentTransaction::class,
        'platform_coupon' => PlatformCoupon::class,
        'platform_payment_gateway' => PlatformPaymentGateway::class,
        'legal_document' => LegalDocument::class,
        'tenant_registration' => TenantRegistration::class,
        'module_notice' => ModuleNotice::class,
        'platform_support_conversation' => PlatformSupportConversation::class,
        'platform_support_message_attachment' => PlatformSupportMessageAttachment::class,

        // Both contexts (spatie/laravel-permission)
        'role' => Role::class,
        'permission' => Permission::class,

        // Both contexts (CMS, §24.1)
        'cms_page' => CmsPage::class,
        'cms_blog_post' => CmsBlogPost::class,
        'cms_banner' => CmsBanner::class,
        'cms_testimonial' => CmsTestimonial::class,
        'contact_submission' => ContactSubmission::class,

        // Tenant
        'user' => User::class,
        'customer' => Customer::class,
        'customer_group' => CustomerGroup::class,
        'seller' => Seller::class,
        'driver' => Driver::class,
        'storefront_setting' => StorefrontSetting::class,
        'data_export' => DataExport::class,
        'custom_field_definition' => CustomFieldDefinition::class,
        'tenant_payment_setting' => TenantPaymentSetting::class,
        'sms_gateway_setting' => SmsGatewaySetting::class,
        'whatsapp_setting' => WhatsAppSetting::class,
    ];

    /**
     * Route parameters that always bind numeric IDs (spec §70.6).
     *
     * @var list<string>
     */
    public const array NUMERIC_ROUTE_PARAMETERS = [
        'user', 'role', 'server', 'plan', 'price', 'document', 'coupon', 'transaction', 'subscription',
        'affiliate', 'referral', 'commission', 'notice', 'conversation', 'customer', 'address', 'group',
    ];
}
