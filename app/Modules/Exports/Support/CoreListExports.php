<?php

declare(strict_types=1);

namespace App\Modules\Exports\Support;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Inventory\Models\Inventory;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Users\Models\User;
use Illuminate\Validation\Rule;

/**
 * The list exports of core commerce (D-134). Each takes the filters of its
 * admin list and reuses that list's query from the owning service, so a
 * list and its export never disagree; requires the list's view
 * permission; and honours the staff warehouse scope (§25.3) through the
 * requester in ExportContext, never through a parameter. Rows stream in
 * chunks.
 */
final class CoreListExports
{
    public static function register(ExportRegistry $registry): void
    {
        $range = ['from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date', 'after_or_equal:from']];

        $registry->register(new ExportDefinition(
            type: 'customers',
            label: 'Customers',
            rules: ['search' => ['sometimes', 'string', 'max:100'], 'customer_group_id' => ['sometimes', 'integer'], 'is_active' => ['sometimes', 'boolean']],
            columns: ['id' => 'ID', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'customer_group' => 'Group', 'is_active' => 'Active',
                'email_verified' => 'Email verified', 'created_at' => 'Created', 'last_login_at' => 'Last login'],
            rows: static fn (array $p): iterable => app(CustomerService::class)->customersQuery([...$p, 'exclude_anonymized' => true])->with('group:id,name')->lazyById(1000)
                ->map(static fn (Customer $c): array => [
                    'id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'customer_group' => $c->group?->name,
                    'is_active' => $c->is_active ? 'yes' : 'no', 'email_verified' => $c->email_verified_at !== null ? 'yes' : 'no',
                    'created_at' => $c->created_at->toIso8601String(), 'last_login_at' => $c->last_login_at?->toIso8601String(),
                ]),
            permission: 'customers.view',
        ));

        $registry->register(new ExportDefinition(
            type: 'products',
            label: 'Products',
            rules: ['search' => ['sometimes', 'string', 'max:100'], 'product_type' => ['sometimes', Rule::in(Product::TYPES)], 'is_active' => ['sometimes', 'boolean'],
                'brand_id' => ['sometimes', 'integer'], 'category_id' => ['sometimes', 'integer']],
            columns: ['id' => 'ID', 'sku' => 'SKU', 'name' => 'Name', 'product_type' => 'Type', 'price' => 'Price', 'compare_at_price' => 'Compare-at price',
                'cost_price' => 'Cost price', 'brand' => 'Brand', 'categories' => 'Categories', 'is_active' => 'Active', 'available_stock' => 'Available stock', 'created_at' => 'Created'],
            rows: static fn (array $p): iterable => app(ProductService::class)->productsQuery($p)->with(['brand:id,name', 'categories:id,slug'])
                ->select('products.*')->addSelect(['stock_available' => Inventory::query()->selectRaw('coalesce(sum(quantity - reserved_quantity), 0)')->whereColumn('product_id', 'products.id')])
                ->lazyById(500, 'products.id', 'id')
                ->map(static fn (Product $x): array => [
                    'id' => $x->id, 'sku' => $x->sku, 'name' => $x->name, 'product_type' => $x->product_type, 'price' => (string) $x->price,
                    'compare_at_price' => $x->compare_at_price === null ? null : (string) $x->compare_at_price, 'cost_price' => $x->cost_price === null ? null : (string) $x->cost_price,
                    'brand' => $x->brand?->name, 'categories' => $x->categories->pluck('slug')->implode(','), 'is_active' => $x->is_active ? 'yes' : 'no',
                    'available_stock' => $x->isPhysical() ? bcadd((string) $x->getAttribute('stock_available'), '0', 3) : null,
                    'created_at' => $x->created_at->toIso8601String(),
                ]),
            permission: 'products.view',
        ));

        $registry->register(new ExportDefinition(
            type: 'product_variants',
            label: 'Product variants',
            rules: ['product_id' => ['sometimes', 'integer'], 'is_active' => ['sometimes', 'boolean']],
            columns: ['id' => 'ID', 'product_id' => 'Product ID', 'product' => 'Product', 'sku' => 'SKU', 'barcode' => 'Barcode', 'options' => 'Options',
                'price' => 'Price', 'cost_price' => 'Cost price', 'is_active' => 'Active'],
            rows: static fn (array $p): iterable => ProductVariant::query()->with(['product:id,name', 'optionValues.option:id,name'])
                ->when(isset($p['product_id']), static fn ($q) => $q->where('product_id', $p['product_id']))
                ->when(array_key_exists('is_active', $p), static fn ($q) => $q->where('is_active', (bool) $p['is_active']))->lazyById(500)
                ->map(static fn (ProductVariant $v): array => [
                    'id' => $v->id, 'product_id' => $v->product_id, 'product' => $v->product?->name, 'sku' => $v->sku, 'barcode' => $v->barcode,
                    'options' => $v->optionValues->map(static fn ($o): string => $o->option->name.': '.$o->value)->implode('; '),
                    'price' => $v->price === null ? null : (string) $v->price, 'cost_price' => $v->cost_price === null ? null : (string) $v->cost_price, 'is_active' => $v->is_active ? 'yes' : 'no',
                ]),
            permission: 'products.view',
        ));

        $registry->register(new ExportDefinition(
            type: 'categories',
            label: 'Categories',
            rules: ['is_active' => ['sometimes', 'boolean']],
            columns: ['id' => 'ID', 'slug' => 'Slug', 'name' => 'Name', 'parent_slug' => 'Parent slug', 'is_active' => 'Active', 'sort_order' => 'Sort order'],
            rows: static fn (array $p): iterable => Category::query()->with('parent:id,slug')
                ->when(array_key_exists('is_active', $p), static fn ($q) => $q->where('is_active', (bool) $p['is_active']))->lazyById(1000)
                ->map(static fn (Category $c): array => [
                    'id' => $c->id, 'slug' => $c->slug, 'name' => $c->name, 'parent_slug' => $c->parent?->slug, 'is_active' => $c->is_active ? 'yes' : 'no', 'sort_order' => $c->sort_order,
                ]),
            permission: 'categories.view',
        ));

        $registry->register(new ExportDefinition(
            type: 'inventory',
            label: 'Stock by warehouse',
            rules: ['warehouse_id' => ['sometimes', 'integer'], 'product_id' => ['sometimes', 'integer']],
            columns: ['warehouse' => 'Warehouse', 'sku' => 'SKU', 'product' => 'Product', 'quantity' => 'On hand', 'reserved' => 'Reserved', 'available' => 'Available'],
            rows: static function (array $p): iterable {
                $visible = self::visibleWarehouses();

                return Inventory::query()->with(['warehouse:id,name,code', 'product:id,name,sku', 'variant:id,sku'])
                    ->when($visible !== null, static fn ($q) => $q->whereIn('warehouse_id', $visible ?: [0]))
                    ->when(isset($p['warehouse_id']), static fn ($q) => $q->where('warehouse_id', $p['warehouse_id']))
                    ->when(isset($p['product_id']), static fn ($q) => $q->where('product_id', $p['product_id']))->lazyById(1000)
                    ->map(static fn (Inventory $i): array => [
                        'warehouse' => $i->warehouse?->code ?? $i->warehouse?->name, 'sku' => $i->variant?->sku ?? $i->product?->sku, 'product' => $i->product?->name,
                        'quantity' => (string) $i->quantity, 'reserved' => (string) $i->reserved_quantity, 'available' => $i->available(),
                    ]);
            },
            permission: 'inventory.view',
        ));

        $orderRules = [...$range, 'status' => ['sometimes', Rule::in(Order::STATUSES)], 'payment_status' => ['sometimes', Rule::in(Order::PAYMENT_STATUSES)],
            'order_source' => ['sometimes', Rule::in(Order::SOURCES)], 'customer_id' => ['sometimes', 'integer'], 'warehouse_id' => ['sometimes', 'integer'],
            'is_test' => ['sometimes', 'boolean'], 'search' => ['sometimes', 'string', 'max:100']];

        $registry->register(new ExportDefinition(
            type: 'orders',
            label: 'Orders',
            rules: $orderRules,
            columns: ['order_number' => 'Order', 'placed_at' => 'Placed', 'status' => 'Status', 'payment_status' => 'Payment', 'order_source' => 'Source',
                'customer_name' => 'Customer', 'customer_email' => 'Email', 'currency_code' => 'Currency', 'subtotal' => 'Subtotal', 'discount_amount' => 'Discount',
                'shipping_amount' => 'Shipping', 'tax_amount' => 'Tax', 'total' => 'Total', 'is_test' => 'Test'],
            rows: static fn (array $p): iterable => app(OrderService::class)->ordersQuery($p, self::requester())->lazyById(1000)
                ->map(static fn (Order $o): array => [
                    'order_number' => $o->order_number, 'placed_at' => $o->placed_at->toIso8601String(), 'status' => $o->status, 'payment_status' => $o->payment_status,
                    'order_source' => $o->order_source, 'customer_name' => $o->customer_name, 'customer_email' => $o->customer_email, 'currency_code' => $o->currency_code,
                    'subtotal' => (string) $o->subtotal, 'discount_amount' => (string) $o->discount_amount, 'shipping_amount' => (string) $o->shipping_amount,
                    'tax_amount' => (string) $o->tax_amount, 'total' => (string) $o->total, 'is_test' => $o->is_test ? 'yes' : 'no',
                ]),
            permission: 'orders.view',
        ));

        $registry->register(new ExportDefinition(
            type: 'order_items',
            label: 'Order lines',
            rules: $orderRules,
            columns: ['order_number' => 'Order', 'placed_at' => 'Placed', 'order_status' => 'Order status', 'sku' => 'SKU', 'name' => 'Item', 'quantity' => 'Quantity',
                'unit_price' => 'Unit price', 'discount_amount' => 'Discount', 'tax_amount' => 'Tax', 'line_total' => 'Line total', 'currency_code' => 'Currency', 'warehouse' => 'Warehouse'],
            rows: static function (array $p): iterable {
                $visible = self::visibleWarehouses();

                return OrderItem::query()->with(['order:id,order_number,placed_at,status,currency_code', 'warehouse:id,code,name'])
                    ->whereIn('order_id', app(OrderService::class)->ordersQuery($p, self::requester())->select('orders.id'))
                    // A narrowed viewer sees only the lines of its warehouses.
                    ->when($visible !== null, static fn ($q) => $q->whereIn('warehouse_id', $visible ?: [0]))->lazyById(1000)
                    ->map(static fn (OrderItem $i): array => [
                        'order_number' => $i->order->order_number, 'placed_at' => $i->order->placed_at->toIso8601String(), 'order_status' => $i->order->status,
                        'sku' => $i->sku_snapshot, 'name' => $i->name_snapshot, 'quantity' => (string) $i->quantity, 'unit_price' => (string) $i->unit_price,
                        'discount_amount' => (string) $i->discount_amount, 'tax_amount' => (string) $i->tax_amount, 'line_total' => (string) $i->line_total,
                        'currency_code' => $i->order->currency_code, 'warehouse' => $i->warehouse?->code ?? $i->warehouse?->name,
                    ]);
            },
            permission: 'orders.view',
        ));

        $registry->register(new ExportDefinition(
            type: 'order_payments',
            label: 'Payments and refunds',
            rules: [...$range, 'kind' => ['sometimes', Rule::in(['payment', 'refund', 'chargeback'])], 'status' => ['sometimes', Rule::in(['pending', 'successful', 'failed'])],
                'payment_method' => ['sometimes', Rule::in(OrderPayment::METHODS)]],
            columns: ['reference' => 'Reference', 'order_number' => 'Order', 'kind' => 'Kind', 'payment_method' => 'Method', 'provider' => 'Provider', 'status' => 'Status',
                'amount_paid' => 'Amount', 'currency_code' => 'Currency', 'paid_at' => 'Paid at', 'created_at' => 'Created'],
            rows: static fn (array $p): iterable => OrderPayment::query()->with('order:id,order_number')
                ->whereIn('order_id', app(OrderService::class)->ordersQuery([], self::requester())->select('orders.id'))
                ->when(isset($p['kind']), static fn ($q) => $q->where('kind', $p['kind']))
                ->when(isset($p['status']), static fn ($q) => $q->where('status', $p['status']))
                ->when(isset($p['payment_method']), static fn ($q) => $q->where('payment_method', $p['payment_method']))
                ->when(isset($p['from']), static fn ($q) => $q->where('created_at', '>=', $p['from']))
                ->when(isset($p['to']), static fn ($q) => $q->where('created_at', '<=', $p['to']))->lazyById(1000)
                ->map(static fn (OrderPayment $x): array => [
                    'reference' => $x->reference, 'order_number' => $x->order?->order_number, 'kind' => $x->kind, 'payment_method' => $x->payment_method,
                    'provider' => $x->provider, 'status' => $x->status, 'amount_paid' => (string) $x->amount_paid, 'currency_code' => $x->currency_code,
                    'paid_at' => $x->paid_at?->toIso8601String(), 'created_at' => $x->created_at->toIso8601String(),
                ]),
            permission: 'order-payments.view',
        ));

        $registry->register(new ExportDefinition(
            type: 'shipments',
            label: 'Shipments',
            rules: [...$range, 'status' => ['sometimes', Rule::in(['pending', 'dispatched', 'in_transit', 'delivered', 'failed', 'cancelled'])], 'warehouse_id' => ['sometimes', 'integer']],
            columns: ['id' => 'ID', 'order_number' => 'Order', 'warehouse' => 'Warehouse', 'fulfillment_type' => 'Fulfilment', 'carrier' => 'Carrier',
                'tracking_number' => 'Tracking number', 'status' => 'Status', 'dispatched_at' => 'Dispatched', 'delivered_at' => 'Delivered'],
            rows: static function (array $p): iterable {
                $visible = self::visibleWarehouses();

                return Shipment::query()->with(['order:id,order_number', 'warehouse:id,code,name'])
                    ->when($visible !== null, static fn ($q) => $q->whereIn('warehouse_id', $visible ?: [0]))
                    ->when(isset($p['warehouse_id']), static fn ($q) => $q->where('warehouse_id', $p['warehouse_id']))
                    ->when(isset($p['status']), static fn ($q) => $q->where('status', $p['status']))
                    ->when(isset($p['from']), static fn ($q) => $q->where('created_at', '>=', $p['from']))
                    ->when(isset($p['to']), static fn ($q) => $q->where('created_at', '<=', $p['to']))->lazyById(1000)
                    ->map(static fn (Shipment $s): array => [
                        'id' => $s->id, 'order_number' => $s->order?->order_number, 'warehouse' => $s->warehouse?->code ?? $s->warehouse?->name,
                        'fulfillment_type' => $s->fulfillment_type, 'carrier' => $s->carrier, 'tracking_number' => $s->tracking_number, 'status' => $s->status,
                        'dispatched_at' => $s->dispatched_at?->toIso8601String(), 'delivered_at' => $s->delivered_at?->toIso8601String(),
                    ]);
            },
            permission: 'shipments.view',
        ));

        $registry->register(new ExportDefinition(
            type: 'returns',
            label: 'Returns',
            rules: [...$range, 'status' => ['sometimes', 'string', 'max:32']],
            columns: ['return_number' => 'Return', 'order_number' => 'Order', 'status' => 'Status', 'resolution_type' => 'Resolution', 'reason' => 'Reason',
                'refund_amount' => 'Refund amount', 'requested_at' => 'Requested', 'resolved_at' => 'Resolved'],
            rows: static fn (array $p): iterable => OrderReturn::query()->with(['order:id,order_number', 'reason:id,label'])
                ->whereIn('order_id', app(OrderService::class)->ordersQuery([], self::requester())->select('orders.id'))
                ->when(isset($p['status']), static fn ($q) => $q->where('status', $p['status']))
                ->when(isset($p['from']), static fn ($q) => $q->where('requested_at', '>=', $p['from']))
                ->when(isset($p['to']), static fn ($q) => $q->where('requested_at', '<=', $p['to']))->lazyById(1000)
                ->map(static fn (OrderReturn $r): array => [
                    'return_number' => $r->return_number, 'order_number' => $r->order?->order_number, 'status' => $r->status, 'resolution_type' => $r->resolution_type,
                    'reason' => $r->reason?->label, 'refund_amount' => $r->refund_amount === null ? null : (string) $r->refund_amount,
                    'requested_at' => $r->requested_at->toIso8601String(), 'resolved_at' => $r->resolved_at?->toIso8601String(),
                ]),
            permission: 'returns.view',
        ));
    }

    /**
     * The staff member who asked (bound by GenerateExport), never a parameter.
     */
    private static function requester(): ?User
    {
        $requester = app()->bound(ExportContext::class) ? app(ExportContext::class)->requestedBy : null;

        return $requester instanceof User ? $requester : null;
    }

    /**
     * @return list<int>|null null = every warehouse
     */
    private static function visibleWarehouses(): ?array
    {
        return app(WarehouseService::class)->visibleIds(self::requester());
    }
}
