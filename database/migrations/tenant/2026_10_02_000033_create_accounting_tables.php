<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: the general ledger (§57.2, §57.3): account categories, the chart
 * of accounts, fiscal years and periods, journal entries and lines (the
 * ledger itself), the balance cache and the posting outbox. Also the
 * account foreign key of order payments (§57.3 "Cash").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('account_type', 16);
            $table->boolean('is_system')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('account_type');
        });

        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 160);
            $table->foreignId('account_category_id')->constrained('account_categories')->restrictOnDelete();
            $table->foreignId('parent_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('system_key', 64)->nullable()->unique();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 8)->default('open');
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('fiscal_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->string('name', 60);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 8)->default('open');
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['starts_on', 'ends_on']);
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->date('entry_date');
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods')->restrictOnDelete();
            $table->string('description');
            $table->string('source', 12);
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('posting_key', 128)->nullable()->unique();
            $table->string('cash_flow_category', 12)->default('operating');
            $table->foreignId('reverses_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index('entry_date');
        });

        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('type', 6);
            $table->decimal('amount', 18, 4);
            $table->string('description')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('account_id');
        });

        Schema::create('account_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods')->restrictOnDelete();
            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->decimal('debit_total', 18, 4)->default(0);
            $table->decimal('credit_total', 18, 4)->default(0);
            $table->decimal('closing_balance', 18, 4)->default(0);
            $table->timestamps();

            $table->unique(['account_id', 'fiscal_period_id']);
        });

        Schema::create('accounting_posting_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('posting_key', 128)->unique();
            $table->string('method', 64);
            $table->string('reference_type', 64);
            $table->unsignedBigInteger('reference_id');
            $table->date('event_date');
            $table->json('payload')->nullable();
            $table->string('status', 8)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        Schema::table('order_payments', function (Blueprint $table): void {
            $table->foreign('account_id')->references('id')->on('chart_of_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
        });

        foreach (['accounting_posting_requests', 'account_balances', 'journal_entry_lines', 'journal_entries', 'fiscal_periods', 'fiscal_years', 'chart_of_accounts', 'account_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
