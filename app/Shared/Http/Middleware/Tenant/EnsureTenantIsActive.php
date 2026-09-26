<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Tenant;

use App\Modules\Billing\Services\SubscriptionAccessService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * tenant.active (spec §6.5, §71): the tenant status gate, then the
 * subscription restriction. Billing routes stay reachable in every
 * restricted subscription state so the owner can always pay.
 */
final readonly class EnsureTenantIsActive
{
    /**
     * Groups whose every request is refused while access is "blocked".
     *
     * @var list<string>
     */
    private const array CUSTOMER_FACING_GROUPS = ['tenant.storefront', 'tenant.customer', 'tenant.seller', 'tenant.driver'];

    public function __construct(private SubscriptionAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw ApiException::forbidden('tenant_unavailable', 'Store not found.');
        }

        match ($tenant->status) {
            TenantStatus::Active => null,
            TenantStatus::AwaitingPayment => throw new ApiException('subscription_payment_required', 'Complete the first payment to open your store.', 402),
            TenantStatus::Provisioning, TenantStatus::ProvisioningFailed => throw new ApiException('tenant_provisioning', 'This store is being set up.', 503),
            TenantStatus::Suspended => throw ApiException::forbidden('tenant_suspended', 'This store is suspended.'),
            TenantStatus::Closed, TenantStatus::Purged => throw new ApiException('tenant_closed', 'This store is closed.', 410),
        };

        $restriction = $this->access->restriction($tenant);

        if ($restriction === SubscriptionAccessService::NONE || $this->isBillingRoute($request)) {
            return $next($request);
        }

        $groups = (array) $request->route()?->middleware();
        $isAdminWrite = in_array('tenant.admin', $groups, true) && ! $request->isMethodSafe();
        $isCustomerFacing = array_intersect(self::CUSTOMER_FACING_GROUPS, $groups) !== [];

        if ($isAdminWrite || ($restriction === SubscriptionAccessService::BLOCKED && $isCustomerFacing)) {
            throw new ApiException('subscription_past_due', 'The subscription is unpaid. Update the payment from the billing page.', 402, [
                'restriction' => $restriction,
            ]);
        }

        return $next($request);
    }

    private function isBillingRoute(Request $request): bool
    {
        return $request->is('api/admin/billing', 'api/admin/billing/*');
    }
}
