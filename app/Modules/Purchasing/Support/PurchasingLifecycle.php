<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Contracts\ModuleLifecycle;
use App\Modules\Purchasing\Models\PurchaseReturnReason;

/**
 * The purchasing module's lifecycle (spec §11.4): default purchase-return
 * reasons, inserted only when absent. Disabling is never blocked: open
 * work finishes through the module's wind-down routes.
 */
final class PurchasingLifecycle implements ModuleLifecycle
{
    public const array DEFAULT_REASONS = ['Damaged on arrival', 'Wrong item shipped', 'Excess quantity', 'Quality issue', 'Expired or near expiry'];

    public function seedDefaults(): void
    {
        foreach (self::DEFAULT_REASONS as $label) {
            if (! PurchaseReturnReason::query()->where('label', $label)->exists()) {
                PurchaseReturnReason::query()->create(['label' => $label]);
            }
        }
    }

    public function disableBlockers(): array
    {
        return [];
    }

    public function onEnabled(): void {}

    public function onDisabled(): void {}
}
