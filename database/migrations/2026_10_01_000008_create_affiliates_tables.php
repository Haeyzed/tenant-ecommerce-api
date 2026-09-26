<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: affiliates (§21A.2), their password broker (§10.4) and clicks (§21A.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->dateTime('email_verified_at')->nullable();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->string('company_name')->nullable();
            $table->string('website_url')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->text('promotion_methods')->nullable();
            $table->string('referral_code', 20)->nullable()->unique();
            $table->string('status', 16)->default('pending');
            $table->decimal('commission_rate', 7, 4)->nullable();
            $table->string('rejection_reason')->nullable();
            $table->string('status_reason')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('platform_users');
            $table->string('payout_method', 32)->nullable();
            $table->text('payout_details')->nullable();
            $table->dateTime('payout_details_updated_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->char('last_login_ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('affiliate_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('affiliate_clicks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('affiliates');
            $table->uuid('visitor_id');
            $table->string('landing_path', 512)->nullable();
            $table->string('referrer_host')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->char('ip_hash', 64);
            $table->string('user_agent', 512)->nullable();
            $table->dateTime('created_at');

            $table->index(['affiliate_id', 'created_at']);
            $table->index('visitor_id');
            $table->index(['ip_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliate_password_reset_tokens');
        Schema::dropIfExists('affiliates');
    }
};
