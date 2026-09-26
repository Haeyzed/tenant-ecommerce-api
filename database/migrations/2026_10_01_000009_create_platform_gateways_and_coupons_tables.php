<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: platform_payment_gateways (§15.10), platform_coupons and targets (§14.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_payment_gateways', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('mode', 8);
            $table->string('public_key')->nullable();
            $table->text('secret_key');
            $table->text('webhook_secret')->nullable();
            $table->text('extra_credentials')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->json('supported_currencies');
            $table->json('supported_country_ids')->nullable();
            $table->dateTime('credentials_verified_at')->nullable();
            $table->dateTime('last_webhook_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('platform_users');
            $table->timestamps();

            $table->unique(['provider', 'mode']);
        });

        Schema::create('platform_coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('discount_type', 16);
            $table->decimal('discount_value', 18, 4);
            $table->char('currency_code', 3)->nullable();
            $table->string('duration', 16);
            $table->unsignedInteger('duration_cycles')->nullable();
            $table->decimal('max_discount_amount', 18, 4)->nullable();
            $table->decimal('min_amount', 18, 4)->nullable();
            $table->boolean('first_subscription_only')->default(true);
            $table->unsignedInteger('usage_limit_total')->nullable();
            $table->unsignedInteger('usage_limit_per_tenant')->default(1);
            $table->unsignedInteger('times_redeemed')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('affiliate_id')->nullable()->constrained('affiliates');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('platform_users');
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('platform_coupon_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_coupon_id')->constrained('platform_coupons')->cascadeOnDelete();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['platform_coupon_id', 'target_type', 'target_id'], 'platform_coupon_targets_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_coupon_targets');
        Schema::dropIfExists('platform_coupons');
        Schema::dropIfExists('platform_payment_gateways');
    }
};
