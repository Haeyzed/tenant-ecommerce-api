<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Social commerce (§69.1). Additions: sync_chain_id, as for WooCommerce, and
| order_account_reference (Facebook Shop orders live on the commerce
| account, which is not the catalogue).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_commerce_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 20);
            $table->text('access_token');
            $table->string('account_reference', 191);
            $table->string('order_account_reference', 191)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('sync_products')->default(true);
            $table->boolean('sync_orders')->default(true);
            $table->foreignId('fulfilment_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->unsignedSmallInteger('sync_interval_minutes')->default(15);
            $table->string('sync_chain_id', 36)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'account_reference']);
        });

        Schema::create('social_commerce_product_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_commerce_account_id')->constrained('social_commerce_accounts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('external_product_id', 191)->nullable();
            $table->string('sync_status', 10)->default('pending');
            $table->string('rejection_reason', 255)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['social_commerce_account_id', 'product_id'], 'social_commerce_product_map_unique');
        });

        Schema::create('social_commerce_order_map', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_commerce_account_id')->constrained('social_commerce_accounts')->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('external_order_id', 191);
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['social_commerce_account_id', 'external_order_id'], 'social_commerce_order_map_unique');
        });

        Schema::create('social_commerce_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_commerce_account_id')->constrained('social_commerce_accounts')->cascadeOnDelete();
            $table->string('sync_type', 8);
            $table->string('trigger', 10);
            $table->string('status', 8);
            $table->unsignedInteger('items_processed')->default(0);
            $table->unsignedInteger('items_failed')->default(0);
            $table->json('error_details')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['social_commerce_account_id', 'started_at'], 'social_commerce_sync_logs_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_commerce_sync_logs');
        Schema::dropIfExists('social_commerce_order_map');
        Schema::dropIfExists('social_commerce_product_map');
        Schema::dropIfExists('social_commerce_accounts');
    }
};
