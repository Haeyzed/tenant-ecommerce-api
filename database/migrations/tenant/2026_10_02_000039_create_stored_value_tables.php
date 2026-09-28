<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Gift cards (§46.1), installment plans (§47.1) and reward points (§54.1),
| plus the constraints the cart and payments ledger waited for, and
| order_items.meta (a gift-card line keeps its recipient until the order
| is confirmed and the card is issued).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('tax_breakdown');
        });

        Schema::create('gift_cards', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->decimal('initial_value', 18, 4);
            $table->decimal('current_balance', 18, 4);
            $table->char('currency_code', 3);
            $table->foreignId('purchased_by_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('source_order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_message', 500)->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('gift_card_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gift_card_id')->constrained('gift_cards')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_payment_id')->constrained('order_payments')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestamp('created_at')->nullable();

            $table->index(['gift_card_id', 'id']);
            $table->index('order_id');
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->foreign('gift_card_id')->references('id')->on('gift_cards')->nullOnDelete();
        });

        Schema::create('installment_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->decimal('total_amount', 18, 4);
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('number_of_installments');
            $table->string('frequency', 16);
            $table->string('status', 16)->default('active');
            $table->date('starts_at');
            $table->string('payment_provider', 32)->nullable();
            $table->text('authorization_token')->nullable();
            $table->unsignedTinyInteger('consecutive_overdue_count')->default(0);
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('installment_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('installment_plan_id')->constrained('installment_plans')->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->decimal('amount_due', 18, 4);
            $table->date('due_date');
            $table->decimal('amount_paid', 18, 4)->default(0);
            $table->dateTime('paid_at')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamps();

            $table->unique(['installment_plan_id', 'sequence']);
            $table->index(['status', 'due_date']);
        });

        Schema::table('order_payments', function (Blueprint $table): void {
            $table->foreign('installment_payment_id')->references('id')->on('installment_payments')->restrictOnDelete();
        });

        Schema::create('reward_point_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->decimal('amount_per_point', 18, 4)->default(1);
            $table->decimal('minimum_order_amount_to_earn', 18, 4)->nullable();
            $table->unsignedInteger('point_expiry_days')->nullable();
            $table->decimal('redeem_amount_per_point', 18, 4)->default(0.01);
            $table->decimal('minimum_order_total_to_redeem', 18, 4)->nullable();
            $table->unsignedInteger('minimum_redeem_points')->nullable();
            $table->unsignedInteger('maximum_redeem_points_per_order')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_reward_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->unsignedInteger('points_balance')->default(0);
            $table->timestamps();
        });

        Schema::create('reward_point_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('type', 16);
            $table->integer('points');
            $table->unsignedInteger('balance_after');
            $table->dateTime('expires_at')->nullable();
            // An earned row whose expiry has been processed (A-38).
            $table->boolean('expiry_processed')->default(false);
            $table->string('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'id']);
            $table->index(['type', 'expiry_processed', 'expires_at']);
            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_point_transactions');
        Schema::dropIfExists('customer_reward_points');
        Schema::dropIfExists('reward_point_settings');

        Schema::table('order_payments', function (Blueprint $table): void {
            $table->dropForeign(['installment_payment_id']);
        });

        Schema::dropIfExists('installment_payments');
        Schema::dropIfExists('installment_plans');

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropForeign(['gift_card_id']);
        });

        Schema::dropIfExists('gift_card_redemptions');
        Schema::dropIfExists('gift_cards');

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};
