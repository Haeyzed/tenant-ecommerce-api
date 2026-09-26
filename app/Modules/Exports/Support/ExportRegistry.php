<?php

declare(strict_types=1);

namespace App\Modules\Exports\Support;

use InvalidArgumentException;

/**
 * Export types, registered by the modules that own the data (spec §19.4).
 * A singleton filled at boot by ModuleServiceProvider.
 */
final class ExportRegistry
{
    /** @var array<string, ExportDefinition> */
    private array $definitions = [];

    public function register(ExportDefinition $definition): void
    {
        $this->definitions[$definition->type] = $definition;
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function get(string $type): ExportDefinition
    {
        return $this->definitions[$type] ?? throw new InvalidArgumentException("Unknown export type [{$type}].");
    }

    /**
     * @return array<string, ExportDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
