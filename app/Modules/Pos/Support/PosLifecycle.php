<?php

declare(strict_types=1);

namespace App\Modules\Pos\Support;

use App\Contracts\ModuleLifecycle;
use App\Modules\Pos\Models\PosSession;

/**
 * The POS module's lifecycle (spec §11.4): it cannot be disabled while a
 * register session is open (§11.5), so no drawer is left uncounted.
 */
final class PosLifecycle implements ModuleLifecycle
{
    public function seedDefaults(): void {}

    public function disableBlockers(): array
    {
        $open = PosSession::query()->where('status', PosSession::OPEN)->count();

        return $open === 0 ? [] : ["Close the {$open} open register ".($open === 1 ? 'session' : 'sessions').' first.'];
    }

    public function onEnabled(): void {}

    public function onDisabled(): void {}
}
