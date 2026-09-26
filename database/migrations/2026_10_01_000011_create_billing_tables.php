<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: subscriptions, payment_transactions, subscription_mrr_movements (§14.1)
 * and platform_coupon_redemptions (§14.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('plan_id')->constrained('plans');
            $table->foreignId('plan_price_id')->constrained('plan_prices');
            $table->char('currency_code', 3);
            $table->string('billing_interval', 16);
            $table->string('gateway', 32)->nullable();
            $table->string('gateway_mode', 8);
            $table->string('gateway_plan_reference')->nullable();
            $table->string('gateway_subscription_reference')->nullable();
            $table->text('authorization_reference')->nullable();
            $table->string('status', 16);
            $table->unsignedInteger('trial_days')->default(0);
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('renews_at')->nullable();
            $table->foreignId('scheduled_plan_id')->nullable()->constrained('plans');
            $table->foreignId('scheduled_plan_price_id')->nullable()->constrained('plan_prices');
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('past_due_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'renews_at']);
        });

        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('subscription_id')->constrained('subscriptions');
            $table->string('type', 16)->default('charge');
            $table->string('mode', 8);
            $table->string('provider', 32);
            $table->string('reference')->unique();
            $table->string('provider_reference')->nullable();
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3);
            $table->string('status', 16);
            $table->foreignId('refund_of_payment_transaction_id')->nullable()->constrained('payment_transactions');
            $table->boolean('is_first_paid_charge')->default(false);
            $table->json('line_items')->nullable();
            $table->decimal('fee', 18, 4)->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('platform_users');
            $table->json('meta')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['mode', 'provider', 'provider_reference']);
            $table->index(['tenant_id', 'type', 'status']);
            $table->index('paid_at');
        });

        Schema::create('subscription_mrr_movements', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->foreignId('subscription_id')->constrained('subscriptions');
            $table->string('type', 16);
            $table->char('currency_code', 3);
            $table->decimal('mrr_before', 18, 4);
            $table->decimal('mrr_after', 18, 4);
            $table->decimal('mrr_delta', 18, 4);
            $table->string('reason', 64);
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index('occurred_at');
            $table->index(['tenant_id', 'occurred_at']);
        });

        Schema::create('platform_coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_coupon_id')->constrained('platform_coupons');
            $table->string('tenant_id');
            $table->foreignId('subscription_id')->constrained('subscriptions');
            $table->string('owner_email');
            $table->string('code_snapshot', 32);
            $table->string('discount_type_snapshot', 16);
            $table->decimal('discount_value_snapshot', 18, 4);
            $table->decimal('max_discount_snapshot', 18, 4)->nullable();
            $table->unsignedInteger('cycles_total');
            $table->unsignedInteger('cycles_applied')->default(0);
            $table->decimal('total_discount_amount', 18, 4)->default(0);
            $table->string('status', 16);
            $table->dateTime('reserved_at');
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['platform_coupon_id', 'subscription_id'], 'platform_coupon_redemptions_unique');
            $table->index('tenant_id');
            $table->index(['owner_email', 'platform_coupon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_coupon_redemptions');
        Schema::dropIfExists('subscription_mrr_movements');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('subscriptions');
    }
};
