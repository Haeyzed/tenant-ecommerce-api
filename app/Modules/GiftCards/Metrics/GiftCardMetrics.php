<?php

declare(strict_types=1);

namespace App\Modules\GiftCards\Metrics;

use App\Modules\GiftCards\Models\GiftCard;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Illuminate\Support\Facades\DB;

/**
 * The gift-cards section (spec §44.3): value issued and redeemed in the
 * range (redemptions net of credits back), and the outstanding liability:
 * the balance on usable cards now. Figures are for cards in the base
 * currency; cards issued in another currency are not converted.
 */
final readonly class GiftCardMetrics
{
    public function __construct(private TenantSettingsService $settings) {}

    public function giftCards(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
        $comparison = $range->comparison();

        $liability = DB::connection('tenant')->table('gift_cards')->where('currency_code', $currency)->where('status', GiftCard::ACTIVE)
            ->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->sum('current_balance');

        return new SectionResult(
            kpis: [
                KpiValue::money('issued_value', 'Issued', $this->issued($range, $currency), $currency, $range,
                    $comparison === null ? null : $this->issued($comparison, $currency), KpiValue::UP_IS_GOOD),
                KpiValue::money('redeemed_value', 'Redeemed', $this->redeemed($range, $currency), $currency, $range,
                    $comparison === null ? null : $this->redeemed($comparison, $currency), KpiValue::NEUTRAL),
                KpiValue::money('outstanding_liability', 'Outstanding balance', bcadd((string) ($liability ?: '0'), '0', 4), $currency, $range, null, KpiValue::NEUTRAL),
            ],
        );
    }

    /**
     * The gift-cards list strip (§44.4): usable cards, their balance, and
     * value issued in the range.
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
        $usable = DB::connection('tenant')->table('gift_cards')->where('status', GiftCard::ACTIVE)
            ->where(static fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        return [
            KpiValue::count('active_cards', 'Active cards', (clone $usable)->count(), $range, null, KpiValue::NEUTRAL),
            KpiValue::money('outstanding_balance', 'Outstanding balance', bcadd((string) ((clone $usable)->where('currency_code', $currency)->sum('current_balance') ?: '0'), '0', 4), $currency, $range, null, KpiValue::NEUTRAL),
            KpiValue::money('issued_value', 'Issued', $this->issued($range, $currency), $currency, $range),
        ];
    }

    private function issued(DateRange $range, string $currency): string
    {
        $sum = DB::connection('tenant')->table('gift_cards')->where('currency_code', $currency)
            ->whereBetween('issued_at', [$range->startUtc(), $range->endUtc()])->sum('initial_value');

        return bcadd((string) ($sum ?: '0'), '0', 4);
    }

    private function redeemed(DateRange $range, string $currency): string
    {
        $sum = DB::connection('tenant')->table('gift_card_redemptions as r')->join('gift_cards as g', 'g.id', '=', 'r.gift_card_id')
            ->where('g.currency_code', $currency)->whereBetween('r.created_at', [$range->startUtc(), $range->endUtc()])->sum('r.amount');

        return bcadd((string) ($sum ?: '0'), '0', 4);
    }
}
