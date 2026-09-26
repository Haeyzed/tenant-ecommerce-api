<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: orders and their lines (§39.1, §39.2), the order-number counter
 * (§39.3) and the order foreign key of promotion redemptions (§37.5).
 * Columns of optional modules (sales agents, POS sessions, restaurant
 * tables, returns, sellers) carry no constraint; the owning module adds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->unsignedBigInteger('next_value');
            $table->timestamps();
        });

        DB::connection($this->getConnection())->table('sequences')->insert(['name' => 'order_number', 'next_value' => 100001, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 32)->unique();
            $table->string('invoice_number', 64)->nullable()->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('guest_token', 64)->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable()->index();
            $table->string('customer_phone', 32)->nullable();
            $table->string('status', 24)->index();
            $table->string('payment_status', 24)->default('unpaid')->index();
            $table->string('order_type', 32)->default('standard');
            $table->string('order_source', 16)->default('online');
            $table->boolean('is_test')->default(false);
            $table->char('currency_code', 3);
            $table->boolean('prices_include_tax')->default(false);
            $table->decimal('subtotal', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('shipping_amount', 18, 4)->default(0);
            $table->decimal('shipping_discount_amount', 18, 4)->default(0);
            $table->decimal('shipping_tax_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->unsignedInteger('reward_points_redeemed')->default(0);
            $table->decimal('reward_points_discount_amount', 18, 4)->default(0);
            $table->decimal('gift_card_amount_applied', 18, 4)->default(0);
            $table->decimal('total', 18, 4);
            $table->decimal('base_currency_amount', 18, 4)->nullable();
            $table->decimal('exchange_rate_used', 18, 8)->nullable();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->restrictOnDelete();
            $table->json('shipping_address')->nullable();
            $table->json('billing_address')->nullable();
            $table->string('payment_gateway', 32)->nullable();
            $table->unsignedBigInteger('sales_agent_id')->nullable();
            $table->unsignedBigInteger('pos_session_id')->nullable();
            $table->unsignedBigInteger('restaurant_table_id')->nullable();
            $table->unsignedBigInteger('replaces_order_return_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->text('customer_note')->nullable();
            $table->dateTime('placed_at')->index();
            $table->dateTime('payment_expires_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index(['is_test', 'confirmed_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->string('name_snapshot');
            $table->string('sku_snapshot', 64)->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('quantity_shipped', 15, 3)->default(0);
            $table->decimal('unit_price', 18, 4);
            $table->string('price_source', 16);
            $table->decimal('unit_cost_snapshot', 18, 4)->nullable();
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('seller_funded_discount_amount', 18, 4)->default(0);
            $table->decimal('tax_rate_applied', 7, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->json('tax_breakdown')->nullable();
            $table->decimal('line_total', 18, 4);
            $table->string('kitchen_status', 16)->nullable();
            $table->dateTime('kitchen_ready_at')->nullable();
            $table->boolean('stock_already_deducted')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'order_id']);
            $table->index('warehouse_id');
        });

        Schema::table('promotion_redemptions', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
        });

        Schema::create('flash_sale_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flash_sale_product_id')->constrained('flash_sale_products')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->string('status', 16)->default('claimed');
            $table->timestamps();

            $table->unique(['flash_sale_product_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flash_sale_claims');

        Schema::table('promotion_redemptions', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });

        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('sequences');
    }
};
