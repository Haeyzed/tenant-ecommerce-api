<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Manufacturing (§64.1). Additions: bill_of_materials.default_key (at most
| one default recipe per product and variant, as a database rule) and
| work_orders.work_order_number (WO-000001).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_of_materials', function (Blueprint $table): void {
            $table->id();
            // Restrict: MySQL forbids cascades on the base columns of a stored generated column.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->string('name', 120);
            $table->boolean('is_default')->default(false);
            $table->string('default_key', 40)->nullable()
                ->storedAs("IF(`is_default`, CONCAT(`product_id`, ':', IFNULL(`product_variant_id`, 0)), NULL)")->unique();
            $table->decimal('yield_quantity', 15, 3)->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'product_variant_id']);
        });

        Schema::create('bill_of_material_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bill_of_material_id')->constrained('bill_of_materials')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('component_product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity_required', 15, 3);
            $table->decimal('unit_cost_snapshot', 18, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('work_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('work_order_number', 16)->unique();
            $table->foreignId('bill_of_material_id')->constrained('bill_of_materials')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('quantity_to_produce', 15, 3);
            $table->string('status', 12)->default('planned');
            $table->date('scheduled_start_date')->nullable();
            $table->date('scheduled_end_date')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_start_date']);
        });

        Schema::create('work_order_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('component_product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity_required', 15, 3);
            $table->decimal('quantity_consumed', 15, 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_materials');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('bill_of_material_items');
        Schema::dropIfExists('bill_of_materials');
    }
};
