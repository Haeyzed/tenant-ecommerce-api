<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Optional lifecycle of an optional tenant module (spec §11.4). Registered by
 * the module's "lifecycle" entry in config/modules.php. Every method runs in
 * the tenant context.
 */
interface ModuleLifecycle
{
    /**
     * Idempotent default data, run the first time the module is enabled and
     * by the defaults sync while it is enabled (spec §12.6 step 5).
     */
    public function seedDefaults(): void;

    /**
     * Reasons the tenant may not disable the module right now. An empty
     * array allows it.
     *
     * @return list<string>
     */
    public function disableBlockers(): array;

    public function onEnabled(): void;

    public function onDisabled(): void;
}
