<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: product reviews and wishlists (§42). A customer reviews a product
 * at most once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 150)->nullable();
            $table->text('body');
            $table->string('status', 16);
            $table->string('rejection_reason')->nullable();
            $table->boolean('is_verified_purchase')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'customer_id']);
            $table->index(['product_id', 'status']);
            $table->index('status');
        });

        Schema::create('wishlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->dateTime('created_at')->useCurrent();

            $table->unique(['customer_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('product_reviews');
    }
};
