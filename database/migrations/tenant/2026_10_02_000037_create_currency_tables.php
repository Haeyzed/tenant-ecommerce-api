<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Multi-currency (spec §48.1). Rates are stored as 1 base = rate target; a
| record's exchange_rate_used is the opposite direction (1 record currency
| = rate base), the multiplier accounting and metrics already apply. Both
| are widened to 12 decimal places so weak-currency conversions (e.g. NGN
| into USD) keep their precision.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_currencies', function (Blueprint $table): void {
            $table->id();
            $table->char('currency_code', 3)->unique();
            $table->boolean('is_base')->default(false);
            // Exactly one base row: NULL for the others, so the unique index allows many.
            $table->unsignedTinyInteger('base_marker')->nullable()->storedAs('IF(is_base, 1, NULL)')->unique();
            $table->boolean('is_active')->default(true);
            $table->string('display_symbol', 8)->nullable();
            $table->timestamps();
        });

        Schema::create('product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedBigInteger('variant_key')->storedAs('COALESCE(product_variant_id, 0)');
            $table->char('currency_code', 3);
            $table->decimal('price', 18, 4);
            $table->decimal('compare_at_price', 18, 4)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'variant_key', 'currency_code']);
            $table->index('currency_code');
        });

        Schema::create('currency_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('base_currency_code', 3);
            $table->char('target_currency_code', 3);
            $table->decimal('rate', 24, 12);
            // manual: set by staff, never overwritten by the provider; provider: fetched.
            $table->string('source', 16)->default('manual');
            $table->dateTime('fetched_at');
            $table->timestamps();

            $table->unique(['base_currency_code', 'target_currency_code']);
        });

        foreach (['orders', 'order_payments', 'expenses', 'income_entries'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->decimal('exchange_rate_used', 24, 12)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['orders', 'order_payments', 'expenses', 'income_entries'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->decimal('exchange_rate_used', 18, 8)->nullable()->change();
            });
        }

        Schema::dropIfExists('currency_exchange_rates');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('tenant_currencies');
    }
};
