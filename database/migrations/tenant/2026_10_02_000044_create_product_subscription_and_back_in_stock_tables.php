<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Product subscriptions (§55.1) and back-in-stock alerts (§56). Additions:
| customer_subscriptions.last_renewal_error (why a due renewal could not be
| made, for staff), customer_subscription_orders.is_renewal and
| billing_date, and back_in_stock_subscriptions.pending_key, which makes
| "one un-notified row per product, variant and email" a database rule.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('interval', 10);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'interval', 'interval_count'], 'product_subscription_plans_schedule_unique');
        });

        Schema::create('customer_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('product_subscription_plan_id')->constrained('product_subscription_plans')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->foreignId('address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->nullOnDelete();
            $table->char('currency_code', 3);
            $table->string('status', 16)->default('pending_payment');
            $table->string('payment_provider', 32)->nullable();
            $table->text('payment_method_token')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->unsignedInteger('failed_renewal_count')->default(0);
            $table->string('last_renewal_error', 255)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_billing_date']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('customer_subscription_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_subscription_id')->constrained('customer_subscriptions')->cascadeOnDelete();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->boolean('is_renewal')->default(false);
            $table->date('billing_date')->nullable();
            $table->dateTime('created_at');
        });

        Schema::create('back_in_stock_subscriptions', function (Blueprint $table): void {
            $table->id();
            // Restrict: MySQL forbids cascading actions on the base columns of a stored
            // generated column (pending_key). Products and variants are soft-deleted.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('email');
            $table->dateTime('notified_at')->nullable();
            // One un-notified row per product, variant and email: NULL once notified.
            $table->string('pending_key', 300)->nullable()
                ->storedAs("IF(`notified_at` IS NULL, CONCAT(`product_id`, ':', IFNULL(`product_variant_id`, 0), ':', `email`), NULL)")->unique();
            $table->dateTime('created_at');

            $table->index(['product_id', 'product_variant_id', 'notified_at'], 'back_in_stock_product_pending_index');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('back_in_stock_subscriptions');
        Schema::dropIfExists('customer_subscription_orders');
        Schema::dropIfExists('customer_subscriptions');
        Schema::dropIfExists('product_subscription_plans');
    }
};
