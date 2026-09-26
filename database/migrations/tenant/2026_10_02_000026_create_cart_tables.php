<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: carts and their items (§38.1). A cart stores no prices,
 * discounts or reservations; totals are recomputed on every read.
 * gift_card_id gets its foreign key with gift cards (§46).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->unique()->constrained('customers')->cascadeOnDelete();
            $table->string('guest_token', 64)->nullable()->unique();
            $table->char('currency_code', 3);
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->unsignedBigInteger('gift_card_id')->nullable();
            $table->unsignedInteger('reward_points_to_redeem')->nullable();
            $table->dateTime('last_activity_at');
            $table->timestamps();

            $table->index('last_activity_at');
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->unsignedBigInteger('variant_key')->storedAs('COALESCE(`product_variant_id`, 0)');
            $table->decimal('quantity', 15, 3);
            $table->timestamps();

            $table->unique(['cart_id', 'product_id', 'variant_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
