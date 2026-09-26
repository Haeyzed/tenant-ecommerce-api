<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: affiliate_referrals (§21A.4), affiliate_payouts (§21A.6) and
 * affiliate_commissions (§21A.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('affiliates');
            $table->string('tenant_id')->unique();
            $table->foreignId('tenant_registration_id')->nullable()->constrained('tenant_registrations')->nullOnDelete();
            $table->foreignId('affiliate_click_id')->nullable()->constrained('affiliate_clicks')->nullOnDelete();
            $table->foreignId('platform_coupon_id')->nullable()->constrained('platform_coupons');
            $table->string('source', 24);
            $table->string('status', 16)->default('registered');
            $table->string('ineligible_reason', 48)->nullable();
            $table->json('risk_flags')->nullable();
            $table->boolean('requires_review')->default(false);
            $table->dateTime('attributed_at');
            $table->dateTime('conversion_deadline')->nullable();
            $table->dateTime('converted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('platform_users');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['affiliate_id', 'status']);
            $table->index(['status', 'conversion_deadline']);
        });

        Schema::create('affiliate_payouts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('affiliate_id')->constrained('affiliates');
            $table->char('currency_code', 3);
            $table->decimal('amount', 18, 4);
            $table->unsignedInteger('commission_count');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16)->default('pending');
            $table->string('payout_method', 32);
            $table->text('payout_details_snapshot');
            $table->string('external_reference')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('platform_users');
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('platform_users');
            $table->timestamps();

            $table->unique(['affiliate_id', 'currency_code', 'period_end'], 'affiliate_payouts_period_unique');
            $table->index('status');
        });

        Schema::create('affiliate_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('affiliates');
            $table->foreignId('affiliate_referral_id')->constrained('affiliate_referrals');
            $table->string('tenant_id');
            $table->foreignId('subscription_id')->constrained('subscriptions');
            $table->string('type', 16);
            $table->foreignId('reverses_commission_id')->nullable()->constrained('affiliate_commissions');
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions');
            $table->decimal('base_amount', 18, 4);
            $table->char('currency_code', 3);
            $table->decimal('commission_rate_applied', 7, 4);
            $table->decimal('amount', 18, 4);
            $table->decimal('original_amount', 18, 4)->nullable();
            $table->string('status', 16);
            $table->boolean('requires_review')->default(false);
            $table->dateTime('hold_until');
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('platform_users');
            $table->dateTime('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->foreignId('affiliate_payout_id')->nullable()->constrained('affiliate_payouts');
            $table->dateTime('paid_at')->nullable();
            $table->unsignedBigInteger('commission_referral_key')->nullable()->storedAs("case when `type` = 'commission' then `affiliate_referral_id` else null end");
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['payment_transaction_id', 'type']);
            $table->unique('commission_referral_key');
            $table->index(['affiliate_id', 'status']);
            $table->index(['status', 'hold_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
        Schema::dropIfExists('affiliate_payouts');
        Schema::dropIfExists('affiliate_referrals');
    }
};
