<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\ApiDocs\ServiceValidationParametersExtractor;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\ServerVariable;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * API documentation (dedoc/scramble), one OpenAPI document per context
 * (spec §70.2):
 *
 * - landlord (the default API): routes named landlord.*, on the landlord
 *   domain. UI /docs/api, JSON /docs/api.json.
 * - tenant: routes named tenant.*, on a tenant's domain. UI
 *   /docs/api/tenant, JSON /docs/api/tenant.json.
 *
 * `php artisan scramble:export --api=landlord|tenant` writes the files
 * imported into Postman (docs/api). Docs routes are local-only
 * (RestrictedDocsAccess).
 */
final class ApiDocsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(Scramble::class)) {
            return;
        }

        $root = (string) config('tenancy.root_domain');

        // Bodies validated inside services (both APIs; registered first so
        // the tenant API, cloned from the default, inherits it).
        Scramble::configure()->parametersExtractors->append(ServiceValidationParametersExtractor::class);

        Scramble::configure()
            // Everything on the landlord domain, including the tenant
            // payment webhooks (named tenant.* but served here, §40.3).
            ->routes(static fn (Route $route): bool => (self::named($route, 'landlord.') || self::onLandlordDomain($route)) && self::withoutDomainParameter($route))
            ->withDocumentTransformers(static fn (OpenApi $openApi) => self::describe($openApi, 'Landlord API',
                'The platform API on the landlord domain: registration, platform admin, billing, affiliates, the website CMS and tenant payment webhooks. '
                .'Authenticate staff with `Authorization: Bearer {token}` from `POST /admin/auth/login`.'));
        Scramble::configure()->serverVariables->set('landlord_domain', ServerVariable::make($root, null, 'The platform root domain (or localhost).'));

        $tenant = Scramble::registerApi('tenant', [
            'api_domain' => null,
            'servers' => ['Tenant' => 'http://{tenant_host}/api'],
            'export_path' => 'docs/api/tenant.openapi.json',
        ])
            ->routes(static fn (Route $route): bool => self::named($route, 'tenant.') && ! self::onLandlordDomain($route) && Str::startsWith($route->uri(), 'api/'))
            ->expose(ui: 'docs/api/tenant', document: 'docs/api/tenant.json')
            ->withDocumentTransformers(static fn (OpenApi $openApi) => self::describe($openApi, 'Tenant API',
                'One store\'s API, served on the store\'s own domain ({slug}.'.$root.'). Staff routes are under /admin and use a staff bearer token '
                .'from `POST /admin/auth/login`; storefront routes accept an optional customer token, and guests send `X-Guest-Token`.'));
        $tenant->serverVariables->set('tenant_host', ServerVariable::make('demo.'.$root, null, 'The store\'s domain: {slug}.'.$root.' or its custom domain.'));
    }

    private static function named(Route $route, string $prefix): bool
    {
        return Str::startsWith((string) $route->getName(), $prefix);
    }

    private static function onLandlordDomain(Route $route): bool
    {
        return $route->getDomain() === '{landlord_domain}' && Str::startsWith($route->uri(), 'api/');
    }

    /**
     * Landlord routes carry the {landlord_domain} domain parameter, which
     * EnsureLandlordDomain removes before the controller runs. Scramble maps
     * route parameters to the controller signature by position, so the
     * extra name would shift every path parameter (a {provider}/{mode}
     * route documented as {mode}/{mode}). The documented route drops it the
     * same way; the server URL keeps {landlord_domain} as a variable.
     */
    private static function withoutDomainParameter(Route $route): bool
    {
        $names = array_values(array_diff($route->parameterNames(), ['landlord_domain']));

        // Parameters a middleware consumes before the controller (the
        // tenant id of a tenant webhook URL) go last, so the rest pair with
        // the signature by position.
        $signature = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $route->signatureParameters());
        $inSignature = array_values(array_filter($names, static fn (string $n): bool => in_array(Str::camel($n), $signature, true) || in_array($n, $signature, true)));

        $route->parameterNames = [...$inSignature, ...array_values(array_diff($names, $inSignature))];

        return true;
    }

    private static function describe(OpenApi $openApi, string $title, string $description): void
    {
        $openApi->info->title = $title;
        $openApi->info->description = $description;
        $openApi->secure(SecurityScheme::http('bearer'));
    }
}
