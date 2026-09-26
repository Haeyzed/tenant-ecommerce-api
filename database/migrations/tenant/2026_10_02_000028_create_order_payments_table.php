<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: the order payments ledger (§40.1). Every movement of money
 * against an order is one row: payments, refunds (negative) and
 * chargebacks (negative). account_id, installment_payment_id and
 * pos_session_id get their constraints from their modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('kind', 16)->default('payment');
            $table->string('payment_method', 24);
            $table->string('provider', 32)->nullable();
            $table->string('mode', 8);
            $table->string('status', 16);
            $table->string('reference', 64)->unique();
            $table->string('idempotency_key', 128)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->decimal('amount_due', 18, 4)->nullable();
            $table->decimal('amount_received', 18, 4)->nullable();
            $table->decimal('amount_paid', 18, 4);
            $table->decimal('change_given', 18, 4)->nullable();
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate_used', 18, 8)->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->foreignId('refund_of_order_payment_id')->nullable()->constrained('order_payments')->restrictOnDelete();
            $table->unsignedBigInteger('installment_payment_id')->nullable();
            $table->unsignedBigInteger('pos_session_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->unique(['mode', 'kind', 'provider', 'provider_reference'], 'order_payments_provider_reference_unique');
            $table->unique(['order_id', 'kind', 'idempotency_key'], 'order_payments_idempotency_unique');
            $table->index(['kind', 'status', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
