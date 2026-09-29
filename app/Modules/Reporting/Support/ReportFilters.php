<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use Closure;
use Illuminate\Database\Query\Builder;

/**
 * The shared report filters (spec §61.1) over order queries: orders as
 * "o", and order lines as "oi" when the query has them. Product-level
 * filters (warehouse, category, brand, tag) keep the matching lines of a
 * line query, and the orders that contain such a line otherwise.
 */
final class ReportFilters
{
    /** Filters that narrow to lines rather than whole orders. */
    public const array PRODUCT_LEVEL = ['warehouse_id', 'category_id', 'brand_id', 'tag_id'];

    /**
     * @return Closure(Builder, bool): Builder
     */
    public static function orders(ReportContext $ctx): Closure
    {
        return static function (Builder $query, bool $lines) use ($ctx): Builder {
            $query
                ->when($ctx->int('customer_id') !== null, static fn ($q) => $q->where('o.customer_id', $ctx->int('customer_id')))
                ->when($ctx->string('order_source') !== null, static fn ($q) => $q->where('o.order_source', $ctx->string('order_source')))
                ->when($ctx->int('user_id') !== null, static fn ($q) => $q->where('o.created_by_user_id', $ctx->int('user_id')))
                ->when($ctx->string('payment_method') !== null, static fn ($q) => $q->whereExists(static fn ($e) => $e->from('order_payments as fp')
                    ->whereColumn('fp.order_id', 'o.id')->where('fp.kind', 'payment')->where('fp.status', 'successful')
                    ->where('fp.payment_method', $ctx->string('payment_method'))));

            if ($lines) {
                return self::lineConditions($query, $ctx, 'oi');
            }

            if (array_filter(self::PRODUCT_LEVEL, static fn (string $k): bool => $ctx->has($k)) !== []) {
                $query->whereExists(static fn ($e) => self::lineConditions($e->from('order_items as fi')->whereColumn('fi.order_id', 'o.id'), $ctx, 'fi'));
            }

            return $query;
        };
    }

    /**
     * Product-level conditions on a line alias.
     */
    public static function lineConditions(Builder $query, ReportContext $ctx, string $alias): Builder
    {
        $warehouse = $ctx->int('warehouse_id');

        return $query
            ->when($warehouse !== null, static fn ($q) => $q->whereIn("{$alias}.warehouse_id", $ctx->scope->warehouses($warehouse) ?: [0]))
            ->when($ctx->int('category_id') !== null, static fn ($q) => $q->whereExists(static fn ($e) => $e->from('product_categories as fpc')
                ->whereColumn('fpc.product_id', "{$alias}.product_id")->where('fpc.category_id', $ctx->int('category_id'))))
            ->when($ctx->int('brand_id') !== null, static fn ($q) => $q->whereExists(static fn ($e) => $e->from('products as fpb')
                ->whereColumn('fpb.id', "{$alias}.product_id")->where('fpb.brand_id', $ctx->int('brand_id'))))
            ->when($ctx->int('tag_id') !== null, static fn ($q) => $q->whereExists(static fn ($e) => $e->from('product_tag as fpt')
                ->whereColumn('fpt.product_id', "{$alias}.product_id")->where('fpt.tag_id', $ctx->int('tag_id'))));
    }

    /**
     * The note shown when returns cannot be split by product (§44.2: a
     * refund belongs to an order, not to a line).
     *
     * @return list<string>
     */
    public static function notes(ReportContext $ctx): array
    {
        return array_filter(self::PRODUCT_LEVEL, static fn (string $k): bool => $ctx->has($k)) === [] ? []
            : ['Returns are refunds of the orders that contain matching lines, at their full value: a refund is not split by product.'];
    }
}
