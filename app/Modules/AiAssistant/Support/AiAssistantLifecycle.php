<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Support;

use App\Contracts\ModuleLifecycle;
use App\Modules\AiAssistant\Services\AiAssistantService;

/**
 * The assistant's lifecycle (spec §11.4, §62.2): its intents are seeded the
 * first time the module is enabled and kept current by the defaults sync.
 */
final class AiAssistantLifecycle implements ModuleLifecycle
{
    public function seedDefaults(): void
    {
        app(AiAssistantService::class)->seedIntents();
    }

    public function disableBlockers(): array
    {
        return [];
    }

    public function onEnabled(): void {}

    public function onDisabled(): void {}
}
