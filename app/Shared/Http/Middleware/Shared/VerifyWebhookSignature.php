<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Shared;

use App\Shared\Exceptions\ApiException;
use App\Shared\Payments\PaymentGatewayFactory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * verify.webhook (spec §15.6 step 1). Verifies the raw body against the
 * secret of the URL's provider and mode, from the landlord or the current
 * tenant's credentials. A mode without credentials, or an invalid
 * signature, is 401 and never processed; the payload is not stored.
 */
final readonly class VerifyWebhookSignature
{
    public const string DRIVER_ATTRIBUTE = 'payment_gateway';

    public function __construct(private PaymentGatewayFactory $factory) {}

    public function handle(Request $request, Closure $next): Response
    {
        $provider = (string) $request->route('provider');
        $mode = (string) $request->route('mode');

        $driver = tenancy()->initialized
            ? $this->factory->forTenantWebhook($provider, $mode)
            : $this->factory->forPlatformWebhook($provider, $mode);

        $headers = array_change_key_case($request->headers->all(), CASE_LOWER);

        if ($driver === null || ! $driver->verifyWebhookSignature($request->getContent(), $headers)) {
            Log::warning('Rejected payment webhook with an invalid signature or unconfigured mode.', [
                'provider' => $provider,
                'mode' => $mode,
                'tenant_id' => tenant()?->getTenantKey(),
            ]);

            throw new ApiException('unauthenticated', 'Invalid webhook signature.', 401);
        }

        $request->attributes->set(self::DRIVER_ATTRIBUTE, $driver);

        return $next($request);
    }
}
