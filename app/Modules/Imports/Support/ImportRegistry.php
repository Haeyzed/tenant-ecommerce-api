<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

use InvalidArgumentException;

/**
 * Import types, registered by the modules that own the data (D-135).
 */
final class ImportRegistry
{
    /** @var array<string, ImportDefinition> */
    private array $definitions = [];

    public function register(ImportDefinition $definition): void
    {
        $this->definitions[$definition->type] = $definition;
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function get(string $type): ImportDefinition
    {
        return $this->definitions[$type] ?? throw new InvalidArgumentException("Unknown import type [{$type}].");
    }

    /**
     * @return array<string, ImportDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
