<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: warehouses, inventory and its movement ledger, transfers,
 * adjustments and per-warehouse prices (§32, §33, §34). The inventory row
 * is the single source of truth for stock; the database also refuses a
 * negative or over-reserved balance. World ids (city, state, country) live
 * in the landlord database, so they carry no foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 32)->nullable()->unique();
            $table->string('address_line')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('warehouse_user', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unique(['warehouse_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('inventory', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->unsignedBigInteger('variant_key')->storedAs('COALESCE(`product_variant_id`, 0)');
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('reserved_quantity', 15, 3)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'variant_key', 'warehouse_id'], 'inventory_product_variant_warehouse_unique');
            // The lock order of every multi-line operation (§32.6).
            $table->index(['warehouse_id', 'product_id', 'variant_key'], 'inventory_lock_order_index');
        });

        DB::connection($this->getConnection())->statement(
            'ALTER TABLE `inventory` ADD CONSTRAINT `inventory_balance_check` CHECK (`quantity` >= 0 AND `reserved_quantity` >= 0 AND `reserved_quantity` <= `quantity`)'
        );

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_id')->constrained('inventory')->restrictOnDelete();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('movement_type', 32);
            $table->decimal('quantity_delta', 15, 3);
            $table->decimal('reserved_delta', 15, 3);
            $table->decimal('quantity_after', 15, 3);
            $table->decimal('reserved_after', 15, 3);
            $table->decimal('unit_cost_snapshot', 18, 4)->nullable();
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['inventory_id', 'id']);
            $table->index(['product_id', 'created_at']);
            $table->index(['warehouse_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity_sent', 15, 3);
            $table->decimal('quantity_received', 15, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('attachment_media_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained('stock_adjustments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->string('action', 16);
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost_snapshot', 18, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // MySQL refuses a cascading key on a generated column's base column;
            // variants are soft-deleted, so restrict never bites.
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->unsignedBigInteger('variant_key')->storedAs('COALESCE(`product_variant_id`, 0)');
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->decimal('price', 18, 4);
            $table->decimal('compare_at_price', 18, 4)->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'variant_key', 'warehouse_id'], 'warehouse_prices_product_variant_warehouse_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'warehouse_product_prices', 'stock_adjustment_items', 'stock_adjustments', 'stock_transfer_items',
            'stock_transfers', 'inventory_movements', 'inventory', 'warehouse_user', 'warehouses',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
