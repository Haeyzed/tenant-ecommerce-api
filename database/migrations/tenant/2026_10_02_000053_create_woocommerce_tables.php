<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| WooCommerce integration (§68.1, §68.2). Additions: sync_chain_id (the
| self-scheduling run chain, so a restarted chain retires the old one)
| and activated_at (orders are exported from that moment on).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('store_url', 255)->nullable();
            $table->text('consumer_key')->nullable();
            $table->text('consumer_secret')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('sync_products')->default(true);
            $table->boolean('sync_categories')->default(true);
            $table->boolean('sync_orders')->default(true);
            $table->boolean('sync_tax_rates')->default(true);
            $table->string('order_sync_direction', 8)->default('import');
            $table->foreignId('import_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->unsignedSmallInteger('sync_interval_minutes')->default(15);
            $table->string('sync_chain_id', 36)->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('woocommerce_category_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->unique()->constrained('categories')->cascadeOnDelete();
            $table->unsignedBigInteger('woocommerce_category_id')->unique();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('woocommerce_product_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedBigInteger('woocommerce_product_id');
            $table->unsignedBigInteger('woocommerce_variation_id')->nullable();
            // 0 for a parent or simple product, so the pair is unique with MySQL's NULL semantics.
            $table->unsignedBigInteger('variation_key')->storedAs('coalesce(woocommerce_variation_id, 0)');
            $table->dateTime('last_synced_at')->nullable();
            $table->dateTime('stock_dirty_at')->nullable();
            $table->timestamps();

            $table->unique(['woocommerce_product_id', 'variation_key'], 'woocommerce_product_map_remote_unique');
            $table->index(['product_id', 'product_variant_id']);
            $table->index('stock_dirty_at');
        });

        Schema::create('woocommerce_tax_rate_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tax_rate_id')->constrained('tax_rates')->cascadeOnDelete();
            $table->unsignedBigInteger('woocommerce_tax_rate_id')->unique();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('woocommerce_order_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('woocommerce_order_id')->unique();
            $table->string('direction', 8);
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('woocommerce_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('sync_type', 10);
            $table->string('direction', 4);
            $table->string('trigger', 10);
            $table->string('status', 8);
            $table->unsignedInteger('items_processed')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->json('error_details')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['sync_type', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_sync_logs');
        Schema::dropIfExists('woocommerce_order_map');
        Schema::dropIfExists('woocommerce_tax_rate_map');
        Schema::dropIfExists('woocommerce_product_map');
        Schema::dropIfExists('woocommerce_category_map');
        Schema::dropIfExists('woocommerce_settings');
    }
};
