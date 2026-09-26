<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: legal_documents, legal_acceptances (§9.2) and tenant_registrations (§9.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 48);
            $table->string('version', 32);
            $table->string('title');
            $table->longText('body');
            $table->string('status', 16)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->dateTime('effective_at')->nullable();
            $table->boolean('required_at_registration')->default(false);
            $table->boolean('requires_reacceptance')->default(false);
            $table->timestamps();

            $table->unique(['document_type', 'version']);
        });

        Schema::create('tenant_registrations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('business_name');
            $table->string('slug');
            $table->string('owner_name');
            $table->string('email')->index();
            $table->text('password_hash')->nullable();
            $table->unsignedBigInteger('country_id');
            $table->char('default_currency', 3);
            $table->foreignId('plan_price_id')->constrained('plan_prices');
            $table->foreignId('platform_coupon_id')->nullable()->constrained('platform_coupons');
            $table->foreignId('affiliate_click_id')->nullable()->constrained('affiliate_clicks');
            $table->char('verification_token_hash', 64);
            $table->dateTime('verification_expires_at');
            $table->unsignedInteger('verification_attempts')->default(0);
            $table->string('status', 32);
            $table->dateTime('verified_at')->nullable();
            $table->string('tenant_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['status', 'verification_expires_at']);
        });

        Schema::create('legal_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('legal_document_id')->constrained('legal_documents');
            $table->foreignId('tenant_registration_id')->nullable()->constrained('tenant_registrations')->nullOnDelete();
            $table->string('tenant_id')->nullable();
            $table->foreignId('affiliate_id')->nullable()->constrained('affiliates');
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->string('accepted_by_name');
            $table->string('accepted_by_email');
            $table->string('context', 32);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->dateTime('accepted_at');
            $table->dateTime('created_at');

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index('tenant_id');
            $table->index('affiliate_id');
            $table->index('legal_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('tenant_registrations');
        Schema::dropIfExists('legal_documents');
    }
};
