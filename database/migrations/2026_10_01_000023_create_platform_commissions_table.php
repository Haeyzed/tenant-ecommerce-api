<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Platform commission on tenant sales (spec §15.8, UD-07 → D-138): the
| platform's receivable, one row per live payment and one negative row per
| refund of it. Collected on the tenant's next subscription renewal.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_commissions', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            // "payment:{order_payment_id}" or "refund:{order_payment_id}" in the tenant database.
            $table->string('source_reference', 64);
            $table->string('kind', 16);
            $table->string('order_number', 64)->nullable();
            $table->decimal('base_amount', 18, 4);
            $table->decimal('rate', 7, 4);
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3);
            $table->string('status', 16)->default('pending');
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions');
            $table->foreignId('waived_by')->nullable()->constrained('platform_users');
            $table->string('waived_reason')->nullable();
            $table->dateTime('collected_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'source_reference']);
            $table->index(['tenant_id', 'status', 'currency_code']);
            $table->index('payment_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_commissions');
    }
};
