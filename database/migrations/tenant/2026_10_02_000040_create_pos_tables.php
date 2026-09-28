<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Point of sale (§51): registers, sessions and settings, the constraints
| orders and the payments ledger waited for, and pos_terminal_charges, the
| card-terminal charges initiated before a sale (A-35). Each successful
| charge pays exactly one sale.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('terminal_provider', 24)->nullable();
            $table->text('terminal_credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['warehouse_id', 'is_active']);
        });

        Schema::create('pos_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('opening_cash_float', 18, 4);
            $table->decimal('closing_cash_float', 18, 4)->nullable();
            $table->decimal('expected_cash', 18, 4)->nullable();
            $table->decimal('cash_variance', 18, 4)->nullable();
            $table->string('status', 8)->default('open');
            // At most one open session per register: NULL once closed.
            $table->unsignedBigInteger('open_marker')->nullable()->storedAs("IF(`status` = 'open', `pos_register_id`, NULL)")->unique();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['pos_register_id', 'opened_at']);
        });

        Schema::create('pos_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('default_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('default_cashier_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('products_per_page')->default(20);
            $table->boolean('touchscreen_keyboard_enabled')->default(false);
            $table->boolean('table_management_enabled')->default(false);
            $table->boolean('send_sms_after_sale')->default(false);
            $table->boolean('cash_register_enabled')->default(true);
            $table->boolean('print_receipt_by_default')->default(true);
            $table->boolean('play_sound_on_sale')->default(false);
            $table->json('enabled_payment_methods');
            $table->timestamps();
        });

        Schema::create('pos_terminal_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pos_register_id')->constrained('pos_registers')->restrictOnDelete();
            $table->string('provider', 24);
            $table->string('reference', 64)->unique();
            $table->string('provider_reference', 191)->nullable();
            $table->decimal('amount', 18, 4);
            $table->char('currency_code', 3);
            $table->string('status', 12)->default('pending');
            $table->string('card_last4', 4)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->foreignId('order_payment_id')->nullable()->unique()->constrained('order_payments')->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pos_register_id', 'status']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreign('pos_session_id')->references('id')->on('pos_sessions')->restrictOnDelete();
        });

        Schema::table('order_payments', function (Blueprint $table): void {
            $table->foreign('pos_session_id')->references('id')->on('pos_sessions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table): void {
            $table->dropForeign(['pos_session_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['pos_session_id']);
        });

        Schema::dropIfExists('pos_terminal_charges');
        Schema::dropIfExists('pos_settings');
        Schema::dropIfExists('pos_sessions');
        Schema::dropIfExists('pos_registers');
    }
};
