<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Per-item low-stock thresholds (§32.7). Resolution: the variant's value,
| then the product's, then tenant_settings.low_stock_threshold. Null means
| inherit; 0 means never report the item as low (out of stock still is).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('expiry_date');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('low_stock_threshold');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('low_stock_threshold');
        });
    }
};
