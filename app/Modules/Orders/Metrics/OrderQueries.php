<?php

declare(strict_types=1);

namespace App\Modules\Orders\Metrics;

use App\Modules\Orders\Models\Order;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\MetricsScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The shared definitions of the order metrics (spec §44.1, §44.2), so every
 * provider, KPI strip and report (§61) counts the same orders the same way.
 * Reports pass the read connection (§6.6); everything else reads the
 * primary.
 */
final class OrderQueries
{
    /** Converts an order amount to the base currency (§48; 1 until multi-currency). */
    public const string FX = 'COALESCE(o.exchange_rate_used, 1)';

    /** A line's merchandise value at the resolved unit price, tax-exclusive, base currency. */
    public const string LINE_GROSS = '(oi.unit_price * oi.quantity) / (CASE WHEN o.prices_include_tax = 1 THEN 1 + oi.tax_rate_applied / 100 ELSE 1 END) * COALESCE(o.exchange_rate_used, 1)';

    /** A line's discount (every promotion, with its order-level share), tax-exclusive, base currency. */
    public const string LINE_DISCOUNT = 'oi.discount_amount / (CASE WHEN o.prices_include_tax = 1 THEN 1 + oi.tax_rate_applied / 100 ELSE 1 END) * COALESCE(o.exchange_rate_used, 1)';

    /** A line's cost at the time of sale (§5.8), base currency; null when unknown. */
    public const string LINE_COST = 'oi.unit_cost_snapshot * oi.quantity * COALESCE(o.exchange_rate_used, 1)';

    /**
     * Included orders (§44.1): confirmed, live, not cancelled, standard,
     * within the staff scope. Date filtering is the caller's (confirmed_at).
     */
    public static function included(MetricsScope $scope, string $connection = 'tenant'): Builder
    {
        return $scope->orders(DB::connection($connection)->table('orders as o')
            ->where('o.is_test', false)
            ->whereNotNull('o.confirmed_at')
            ->where('o.status', '!=', Order::CANCELLED)
            ->where('o.order_type', 'standard')
            ->whereNull('o.deleted_at'));
    }

    /**
     * Lines of included orders; a narrowed user sees only lines of their
     * warehouses.
     */
    public static function includedLines(MetricsScope $scope, string $connection = 'tenant'): Builder
    {
        return $scope->column(self::included($scope, $connection)->join('order_items as oi', 'oi.order_id', '=', 'o.id'), 'oi.warehouse_id');
    }

    /**
     * Every live order (for status counts by placed_at).
     */
    public static function placed(MetricsScope $scope, string $connection = 'tenant'): Builder
    {
        return $scope->orders(DB::connection($connection)->table('orders as o')->where('o.is_test', false)->whereNull('o.deleted_at'));
    }

    /**
     * Successful live refund (and chargeback) rows of the scoped orders,
     * dated by paid_at.
     *
     * @param  list<string>  $kinds
     */
    public static function reversals(MetricsScope $scope, array $kinds = ['refund'], string $connection = 'tenant'): Builder
    {
        return $scope->orders(DB::connection($connection)->table('order_payments as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->whereIn('op.kind', $kinds)
            ->where('op.status', 'successful')
            ->where('op.mode', 'live')
            ->where('o.is_test', false));
    }

    /**
     * Tax-exclusive value of refunds and chargebacks in the range (§44.2
     * "Returns"): each row less its order's tax share (A-44), in the base
     * currency, gift-card purchases excluded.
     */
    public static function returnsValue(MetricsScope $scope, DateRange $range, string $connection = 'tenant'): string
    {
        $value = self::returns($scope, $connection)
            ->whereBetween('op.paid_at', [$range->startUtc(), $range->endUtc()])
            ->value(DB::raw(self::RETURNS_SUM));

        return bcadd((string) ($value ?? '0'), '0', 4);
    }

    /**
     * The refund and chargeback rows that count as returns (§44.2).
     */
    public static function returns(MetricsScope $scope, string $connection = 'tenant'): Builder
    {
        return self::reversals($scope, ['refund', 'chargeback'], $connection)->where('o.order_type', '!=', 'gift_card_purchase');
    }

    /** SUM of tax-exclusive refund value; see returnsValue(). */
    public const string RETURNS_SUM = 'SUM(-op.amount_paid * (1 - CASE WHEN o.total > 0 THEN o.tax_amount / o.total ELSE 0 END) * COALESCE(op.exchange_rate_used, o.exchange_rate_used, 1))';
}
