<?php

declare(strict_types=1);

namespace App\Shared\Contract;

use App\Modules\Access\Support\PermissionName;
use App\Modules\Access\Support\RoutePermissions;
use App\Modules\Plans\Support\ModuleDefinition;
use App\Modules\Plans\Support\ModuleRegistry;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\Finder\Finder;

/**
 * The machine-readable contract the frontends are built against (frontend
 * spec BG-07): the route manifest, the module, limit and permission
 * registries, the error codes and the storefront presentation options.
 * Everything is derived from the registered routes, config and source, so
 * it can never drift from what the API enforces.
 */
final class FrontendContract
{
    /**
     * Status of each ApiException factory (see ApiException).
     */
    private const array FACTORY_STATUS = ['unprocessable' => 422, 'conflict' => 409, 'forbidden' => 403];

    /**
     * Codes produced by ExceptionRenderer and the module-state gate, which
     * are not string literals at a throw site.
     */
    private const array FRAMEWORK_CODES = [
        'validation_failed' => [422], 'unauthenticated' => [401], 'forbidden' => [403], 'not_found' => [404],
        'method_not_allowed' => [405], 'too_many_requests' => [429], 'invalid_signature' => [403],
        'bad_request' => [400], 'payment_required' => [402], 'state_conflict' => [409], 'gone' => [410],
        'session_expired' => [419], 'maintenance' => [503], 'http_error' => [], 'server_error' => [500],
        'invalid_transition' => [422], 'feature_unavailable' => [403], 'module_disabled' => [403],
        'module_locked' => [403], 'module_suspended' => [403],
    ];

    public function __construct(
        private readonly Router $router,
        private readonly ModuleRegistry $modules,
        private readonly RoutePermissions $permissions,
    ) {}

    /**
     * Every artefact by file name.
     *
     * @return array<string, array<mixed>>
     */
    public function build(): array
    {
        return [
            'routes.landlord.json' => $this->routes('landlord'),
            'routes.tenant.json' => $this->routes('tenant'),
            'modules.json' => $this->moduleList(),
            'limits.json' => $this->limits(),
            'permissions.landlord.json' => $this->permissionSet('landlord'),
            'permissions.tenant.json' => $this->permissionSet('tenant'),
            'error-codes.json' => $this->errorCodes(),
            'storefront.json' => ['themes' => config('storefront.themes'), 'fonts' => config('storefront.fonts')],
        ];
    }

    /**
     * The named API routes of one context with their gates.
     *
     * @return list<array<string, mixed>>
     */
    public function routes(string $context): array
    {
        $groups = $this->router->getMiddlewareGroups();
        $routes = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            $declared = $route->middleware();
            $isLandlord = in_array('landlord.domain', $declared, true);

            if ($name === null || ! str_starts_with($route->uri(), 'api/') || $isLandlord !== ($context === 'landlord')) {
                continue;
            }

            $group = null;
            $middleware = [];

            foreach ($declared as $entry) {
                if (! is_string($entry)) {
                    continue;
                }

                if (isset($groups[$entry])) {
                    $group ??= $entry;
                    array_push($middleware, ...array_filter($groups[$entry], 'is_string'));
                } else {
                    $middleware[] = $entry;
                }
            }

            $middleware = array_values(array_diff($middleware, $route->excludedMiddleware()));
            $routes[] = $this->describe($route, $name, $group, $middleware);
        }

        usort($routes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $routes;
    }

    /**
     * @param  list<string>  $middleware
     * @return array<string, mixed>
     */
    private function describe(Route $route, string $name, ?string $group, array $middleware): array
    {
        $parameter = static function (string $alias) use ($middleware): ?string {
            foreach ($middleware as $entry) {
                if (str_starts_with($entry, $alias.':')) {
                    return substr($entry, strlen($alias) + 1);
                }
            }

            return null;
        };

        $methods = array_values(array_diff($route->methods(), ['HEAD']));
        [$module, $mode] = array_pad(explode(',', (string) $parameter('feature')), 2, null);
        $module = $module !== '' ? $module : null;
        $optionalActor = $parameter('auth.as.optional');
        $definition = $module !== null && $this->modules->has($module) ? $this->modules->get($module) : null;

        return [
            'name' => $name,
            'methods' => $methods,
            'uri' => '/'.$route->uri(),
            'group' => $group,
            'actor' => $parameter('auth.as') ?? $optionalActor,
            'actor_optional' => $optionalActor !== null,
            'module' => $module,
            'module_notice' => $parameter('module.notice'),
            'wind_down' => $module !== null && ($mode === 'wind-down' || $this->modules->isWindDownRoute($module, $name)),
            'read_when_inactive' => $definition !== null && $definition->readWhenInactive
                && $group === 'tenant.admin' && in_array('GET', $methods, true),
            'permission' => in_array('permission.derived', $middleware, true) ? PermissionName::forRoute($route) : null,
            'acting_permission' => isset($route->defaults[RoutePermissions::ACTING_PERMISSION])
                ? (string) $route->defaults[RoutePermissions::ACTING_PERMISSION]
                : null,
            'idempotency' => in_array('idempotency', $middleware, true),
            'usage_limit' => $parameter('usage.limit'),
            'signed' => in_array('signed', $middleware, true),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function moduleList(): array
    {
        return array_values(array_map(static fn (ModuleDefinition $module): array => [
            'key' => $module->key,
            'name' => $module->name,
            'class' => $module->class,
            'section' => $module->section,
            'requires' => $module->requires,
            'activation' => $module->activation,
            'read_when_inactive' => $module->readWhenInactive,
            'permission_groups' => $module->permissionGroups,
            'wind_down' => $module->windDown,
        ], $this->modules->all()));
    }

    /**
     * @return list<array{key: string, label: string, kind: string, unlimited_allowed: bool}>
     */
    private function limits(): array
    {
        $limits = [];

        foreach ((array) config('limits') as $key => $limit) {
            $limits[] = [
                'key' => (string) $key,
                'label' => (string) $limit['label'],
                'kind' => (string) $limit['kind'],
                'unlimited_allowed' => (bool) $limit['unlimited_allowed'],
            ];
        }

        return $limits;
    }

    /**
     * @return array<string, mixed>
     */
    private function permissionSet(string $context): array
    {
        $config = (array) config("permissions.{$context}");

        return [
            'guard' => $config['guard'] ?? null,
            'version' => $config['version'] ?? null,
            'permissions' => $this->permissions->derive($context),
            'protected_roles' => $config['protected'] ?? [],
            'starter_roles' => $config['roles'] ?? [],
        ];
    }

    /**
     * Error codes with their statuses and owning modules, collected from the
     * literal codes at every throw site in app/. A code built at runtime
     * (a variable) cannot be listed; the frontend treats an unknown code
     * by its HTTP status.
     *
     * @return list<array{code: string, statuses: list<int>, modules: list<string>}>
     */
    public function errorCodes(): array
    {
        $codes = [];

        foreach (self::FRAMEWORK_CODES as $code => $statuses) {
            $codes[$code] = ['statuses' => $statuses, 'modules' => ['Shared']];
        }

        $literal = "'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"";
        // [pattern, capture group of the code, status resolver]
        $patterns = [
            // ApiException::conflict('code', …)
            ['/ApiException::(unprocessable|conflict|forbidden)\(\s*\'([a-z0-9_]+)\'/', 2, static fn (array $m): ?int => self::FACTORY_STATUS[$m[1]]],
            // new ApiException('code', 'message', 404) and APIResponse::error('code', 'message', 404);
            // the status is unknown when the message is not a single literal.
            ["/(?:new ApiException|APIResponse::error)\\(\\s*'([a-z0-9_]+)'\\s*(?:,\\s*(?:{$literal})\\s*(?:,\\s*(\\d{3}))?)?/", 1, static fn (array $m): ?int => ($m[2] ?? '') !== '' ? (int) $m[2] : null],
        ];

        $files = Finder::create()->files()->in(app_path())->name('*.php');

        foreach ($files as $file) {
            $source = $file->getContents();
            $module = preg_match('#[\\\\/]Modules[\\\\/]([^\\\\/]+)#', $file->getPathname(), $match) === 1 ? $match[1] : 'Shared';

            foreach ($patterns as [$pattern, $group, $status]) {
                preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

                foreach ($matches as $m) {
                    $code = $m[$group];
                    $codes[$code] ??= ['statuses' => [], 'modules' => []];
                    $resolved = $status($m);

                    if ($resolved !== null) {
                        $codes[$code]['statuses'][] = $resolved;
                    }

                    $codes[$code]['modules'][] = $module;
                }
            }
        }

        ksort($codes);
        $list = [];

        foreach ($codes as $code => $entry) {
            $statuses = array_values(array_unique($entry['statuses']));
            $modules = array_values(array_unique($entry['modules']));
            sort($statuses);
            sort($modules);
            $list[] = ['code' => (string) $code, 'statuses' => $statuses, 'modules' => $modules];
        }

        return $list;
    }
}
