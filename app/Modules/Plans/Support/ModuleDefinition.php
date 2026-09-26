<?php

declare(strict_types=1);

namespace App\Modules\Plans\Support;

/**
 * One entry of config/modules.php (spec §11.4).
 */
final readonly class ModuleDefinition
{
    /**
     * @param  list<string>  $requires
     * @param  list<string>  $permissionGroups
     * @param  list<string>  $customFieldEntities
     * @param  list<string>  $windDown  route names allowed while disabled or locked
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $class,
        public string $section,
        public string $codeModule,
        public array $requires,
        public string $activation,
        public bool $readWhenInactive,
        public array $permissionGroups,
        public array $customFieldEntities,
        public ?string $lifecycle,
        public array $windDown,
    ) {}

    public function isAuto(): bool
    {
        return $this->activation === 'auto';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        return new self(
            key: $key,
            name: (string) $config['name'],
            class: (string) $config['class'],
            section: (string) $config['section'],
            codeModule: (string) $config['code_module'],
            requires: array_values((array) $config['requires']),
            activation: (string) $config['activation'],
            readWhenInactive: (bool) $config['read_when_inactive'],
            permissionGroups: array_values((array) $config['permission_groups']),
            customFieldEntities: array_values((array) $config['custom_field_entities']),
            lifecycle: $config['lifecycle'] ?? null,
            windDown: array_values((array) ($config['wind_down'] ?? [])),
        );
    }
}
