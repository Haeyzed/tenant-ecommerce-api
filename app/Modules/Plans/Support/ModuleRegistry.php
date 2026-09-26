<?php

declare(strict_types=1);

namespace App\Modules\Plans\Support;

use App\Contracts\ModuleLifecycle;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The only reader of config/modules.php (spec §11.12). Context-free.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleDefinition>|null */
    private ?array $definitions = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string, ModuleDefinition>
     */
    public function all(): array
    {
        if ($this->definitions === null) {
            $this->definitions = [];

            foreach ((array) config('modules') as $key => $config) {
                $this->definitions[$key] = ModuleDefinition::fromConfig((string) $key, (array) $config);
            }
        }

        return $this->definitions;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ModuleDefinition
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown module key [{$key}].");
    }

    /**
     * Transitive hard requirements of a module.
     *
     * @return list<string>
     */
    public function requires(string $key): array
    {
        $result = [];
        $stack = $this->get($key)->requires;

        while ($stack !== []) {
            $required = array_shift($stack);

            if (in_array($required, $result, true)) {
                continue;
            }

            $result[] = $required;
            $stack = array_merge($stack, $this->get($required)->requires);
        }

        return $result;
    }

    /**
     * Modules that directly require the given one.
     *
     * @return list<string>
     */
    public function dependents(string $key): array
    {
        return array_values(array_keys(array_filter(
            $this->all(),
            static fn (ModuleDefinition $definition): bool => in_array($key, $definition->requires, true),
        )));
    }

    public function isWindDownRoute(string $key, string $routeName): bool
    {
        return in_array($routeName, $this->get($key)->windDown, true);
    }

    /**
     * @return list<string>
     */
    public function customFieldEntities(string $key): array
    {
        return $this->get($key)->customFieldEntities;
    }

    public function lifecycle(string $key): ?ModuleLifecycle
    {
        $class = $this->get($key)->lifecycle;

        if ($class === null) {
            return null;
        }

        $lifecycle = $this->container->make($class);

        if (! $lifecycle instanceof ModuleLifecycle) {
            throw new InvalidArgumentException("Lifecycle of [{$key}] must implement ModuleLifecycle.");
        }

        return $lifecycle;
    }

    /**
     * Registry consistency problems (spec §73.5): unknown requirements,
     * cycles, missing code-module folders and invalid lifecycles.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->all() as $key => $definition) {
            foreach ($definition->requires as $required) {
                if (! $this->has($required)) {
                    $problems[] = "[{$key}] requires unknown module [{$required}].";
                }
            }

            if (! is_dir(app_path('Modules/'.str_replace('\\', '/', $definition->codeModule)))) {
                $problems[] = "[{$key}] code module [{$definition->codeModule}] does not exist.";
            }

            if ($definition->lifecycle !== null && ! is_subclass_of($definition->lifecycle, ModuleLifecycle::class)) {
                $problems[] = "[{$key}] lifecycle must implement ModuleLifecycle.";
            }

            try {
                if (in_array($key, $this->requires($key), true)) {
                    $problems[] = "[{$key}] has a requirement cycle.";
                }
            } catch (InvalidArgumentException) {
                // Reported above as an unknown requirement.
            }
        }

        return $problems;
    }
}
