<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The marketplace (§50.1, §50.4, §50.5): sellers, seller groups, the seller
| ledger and payouts, plus the seller_id constraints the catalogue, order
| and promotion migrations left to this module (§39.1).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();
            $table->decimal('default_commission_rate', 7, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('sellers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_group_id')->nullable()->constrained('seller_groups')->nullOnDelete();
            $table->string('business_name', 160);
            $table->string('contact_name', 120)->nullable();
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable();
            $table->string('password');
            $table->dateTime('email_verified_at')->nullable();
            $table->string('status', 10)->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->decimal('commission_rate', 7, 4)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('seller_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('gross_amount', 18, 4);
            $table->decimal('commission_amount', 18, 4);
            $table->decimal('net_payable', 18, 4);
            $table->string('status', 8)->default('pending');
            $table->dateTime('paid_at')->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
        });

        Schema::create('seller_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->string('entry_type', 10);
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('gross_amount', 18, 4);
            $table->decimal('commission_rate_applied', 7, 4);
            $table->decimal('commission_amount', 18, 4);
            $table->decimal('net_payable', 18, 4);
            $table->dateTime('available_at')->nullable();
            $table->foreignId('seller_payout_id')->nullable()->constrained('seller_payouts')->restrictOnDelete();
            $table->dateTime('created_at')->useCurrent();

            // Sale entries have no source (NULLs do not collide in a unique
            // index), so the service checks them; reversals are unique per cause.
            $table->unique(['order_item_id', 'entry_type', 'source_type', 'source_id'], 'seller_ledger_entries_cause_unique');
            $table->index(['seller_id', 'seller_payout_id', 'available_at'], 'seller_ledger_entries_payout_index');
            $table->index('order_id');
        });

        foreach (['products', 'order_items', 'promotions', 'promotion_redemptions'] as $table) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->foreign('seller_id')->references('id')->on('sellers')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['promotion_redemptions', 'promotions', 'order_items', 'products'] as $table) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['seller_id']);
            });
        }

        Schema::dropIfExists('seller_ledger_entries');
        Schema::dropIfExists('seller_payouts');
        Schema::dropIfExists('sellers');
        Schema::dropIfExists('seller_groups');
    }
};
