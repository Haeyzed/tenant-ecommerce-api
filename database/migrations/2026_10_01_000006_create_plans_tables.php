<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: plans, prices, features, limits, overrides and activation (§11.6 to §11.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('tagline')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_recommended')->default(false);
            $table->string('marketing_badge')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans');
            $table->char('currency_code', 3);
            $table->string('billing_interval', 16);
            $table->decimal('amount', 18, 4);
            $table->unsignedInteger('trial_days')->nullable();
            $table->boolean('trial_requires_payment_method')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('gateway_references')->nullable();
            $table->timestamps();

            $table->index(['plan_id', 'currency_code', 'billing_interval', 'is_active'], 'plan_prices_lookup_index');
        });

        Schema::create('plan_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
        });

        Schema::create('tenant_features', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('feature_key', 64);
            $table->string('effect', 16);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('reason')->nullable();
            $table->decimal('extra_amount', 18, 4)->nullable();
            $table->char('extra_currency_code', 3)->nullable();
            $table->boolean('billed')->default(true);
            $table->foreignId('overridden_by')->nullable()->constrained('platform_users');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'feature_key']);
        });

        Schema::create('plan_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('limit_key', 64);
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'limit_key']);
        });

        Schema::create('tenant_limit_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('limit_key', 64);
            $table->unsignedBigInteger('limit_value')->nullable();
            $table->decimal('extra_amount', 18, 4)->nullable();
            $table->char('extra_currency_code', 3)->nullable();
            $table->boolean('billed')->default(true);
            $table->boolean('is_active')->default(true);
            $table->dateTime('expires_at')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('overridden_by')->nullable()->constrained('platform_users');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'limit_key']);
        });

        Schema::create('tenant_modules', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->string('module_key', 64);
            $table->string('status', 16);
            $table->dateTime('enabled_at')->nullable();
            $table->dateTime('disabled_at')->nullable();
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->string('changed_by_email')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
        Schema::dropIfExists('tenant_limit_overrides');
        Schema::dropIfExists('plan_limits');
        Schema::dropIfExists('tenant_features');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
