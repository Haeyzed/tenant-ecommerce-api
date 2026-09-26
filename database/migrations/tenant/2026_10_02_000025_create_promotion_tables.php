<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: promotions, their targets and coupons, the redemption ledger and
 * flash sales (§37). promotion_redemptions.order_id and the seller columns
 * get their foreign keys with the Orders and Marketplace modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('public_label')->nullable();
            $table->text('description')->nullable();
            $table->string('trigger', 16);
            $table->string('scope', 16);
            $table->string('discount_type', 16);
            $table->decimal('discount_value', 18, 4)->nullable();
            $table->decimal('max_discount_amount', 18, 4)->nullable();
            $table->decimal('min_subtotal_amount', 18, 4)->nullable();
            $table->decimal('max_subtotal_amount', 18, 4)->nullable();
            $table->decimal('min_eligible_quantity', 15, 3)->nullable();
            $table->decimal('max_eligible_quantity', 15, 3)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedTinyInteger('valid_days_of_week')->nullable();
            $table->time('valid_time_from')->nullable();
            $table->time('valid_time_to')->nullable();
            $table->boolean('applies_to_online')->default(true);
            $table->boolean('applies_to_pos')->default(true);
            $table->boolean('applies_to_sale_items')->default(false);
            $table->boolean('first_order_only')->default(false);
            $table->unsignedInteger('usage_limit_total')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->boolean('is_exclusive')->default(false);
            $table->integer('priority')->default(0);
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'trigger']);
            $table->index('starts_at');
            $table->index('ends_at');
            $table->index('seller_id');
        });

        Schema::create('promotion_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            $table->string('mode', 8);

            $table->unique(['promotion_id', 'target_type', 'target_id']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->restrictOnDelete();
            $table->string('code', 32)->unique();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->foreignId('assigned_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('promotion_id');
        });

        Schema::create('promotion_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->restrictOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->restrictOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_email')->nullable();
            $table->string('promotion_name_snapshot');
            $table->string('public_label_snapshot')->nullable();
            $table->string('coupon_code_snapshot', 32)->nullable();
            $table->string('scope_snapshot', 16);
            $table->string('discount_type_snapshot', 16);
            $table->decimal('discount_value_snapshot', 18, 4)->nullable();
            $table->decimal('discount_amount', 18, 4);
            $table->decimal('base_discount_amount', 18, 4);
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('status', 16);
            $table->boolean('over_limit')->default(false);
            $table->dateTime('committed_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            $table->unique(['promotion_id', 'order_id']);
            $table->index(['promotion_id', 'status']);
            $table->index(['customer_id', 'promotion_id']);
            $table->index(['customer_email', 'promotion_id']);
            $table->index('order_id');
        });

        Schema::create('flash_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('flash_sale_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flash_sale_id')->constrained('flash_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('sale_price', 18, 4);
            $table->decimal('quantity_limit', 15, 3)->nullable();
            $table->decimal('quantity_claimed', 15, 3)->default(0);
            $table->timestamps();

            $table->unique(['flash_sale_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        foreach (['flash_sale_products', 'flash_sales', 'promotion_redemptions', 'coupons', 'promotion_targets', 'promotions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
