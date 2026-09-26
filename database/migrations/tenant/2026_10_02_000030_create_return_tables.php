<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: returns and exchanges (§41). return_number is the customer-facing
 * reference used by the return notifications; order_payments gains the
 * return a refund belongs to, so a refund that completes later settles its
 * return (§40.4 refund(…, ?OrderReturn)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_reasons', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 120);
            $table->boolean('requires_photo')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('order_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_number', 32)->nullable()->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('return_reason_id')->constrained('return_reasons')->restrictOnDelete();
            $table->string('resolution_type', 16);
            $table->string('status', 32)->index();
            $table->boolean('requires_physical_return')->nullable();
            $table->text('customer_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->decimal('refund_amount', 18, 4)->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('customer_id');
        });

        Schema::create('order_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_return_id')->constrained('order_returns')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->string('condition', 16)->nullable();
            $table->boolean('restocked')->default(false);
            $table->foreignId('exchange_for_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('exchange_for_product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('order_payments', function (Blueprint $table): void {
            $table->foreignId('order_return_id')->nullable()->after('refund_of_order_payment_id')->constrained('order_returns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_return_id');
        });

        Schema::dropIfExists('order_return_items');
        Schema::dropIfExists('order_returns');
        Schema::dropIfExists('return_reasons');
    }
};
