<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: billers, expenses and other income (§57.4, feature `expenses`).
 * expenses.supplier_id carries no constraint until purchasing (§49) adds
 * the suppliers table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('category', 16);
            $table->string('account_reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('biller_id')->nullable()->constrained('billers')->restrictOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate_used', 18, 8)->nullable();
            $table->date('expense_date');
            $table->text('description')->nullable();
            $table->string('status', 8)->default('pending');
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('paid_from_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'expense_date']);
            $table->index('supplier_id');
        });

        Schema::create('income_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('income_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('income_category_id')->constrained('income_categories')->restrictOnDelete();
            $table->string('source', 160)->nullable();
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate_used', 18, 8)->nullable();
            $table->date('received_date');
            $table->text('description')->nullable();
            $table->string('status', 8)->default('pending');
            $table->dateTime('received_at')->nullable();
            $table->foreignId('received_into_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'received_date']);
        });
    }

    public function down(): void
    {
        foreach (['income_entries', 'income_categories', 'expenses', 'expense_categories', 'billers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
