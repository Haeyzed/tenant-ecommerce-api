<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Support;

use App\Contracts\ModuleLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * The marketplace module's lifecycle (spec §11.4): it cannot be disabled
 * while any seller has an unpaid balance (§11.5); pay or settle them first.
 */
final class MarketplaceLifecycle implements ModuleLifecycle
{
    public function seedDefaults(): void {}

    public function disableBlockers(): array
    {
        $owed = DB::connection('tenant')->table('seller_ledger_entries')->whereNull('seller_payout_id')
            ->groupBy('seller_id')->havingRaw('SUM(net_payable) > 0')->pluck('seller_id')->count();

        return $owed === 0 ? [] : ["{$owed} ".($owed === 1 ? 'seller has' : 'sellers have').' an unpaid balance. Pay them out first.'];
    }

    public function onEnabled(): void {}

    public function onDisabled(): void {}
}
