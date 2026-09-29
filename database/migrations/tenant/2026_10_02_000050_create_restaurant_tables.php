<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Restaurant (§65). orders.restaurant_table_id and the order_items kitchen
| columns exist since the order tables (§39.2); the foreign key is added
| here, with the restaurant tables.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_floors', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('restaurant_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_floor_id')->constrained('restaurant_floors')->restrictOnDelete();
            $table->string('name', 40);
            $table->unsignedSmallInteger('seats');
            $table->string('status', 10)->default('available');
            $table->timestamps();

            $table->unique(['restaurant_floor_id', 'name']);
            $table->index('status');
        });

        Schema::create('restaurant_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name', 120);
            $table->string('customer_phone', 32)->nullable();
            $table->unsignedSmallInteger('party_size');
            $table->dateTime('reservation_time');
            $table->string('status', 10)->default('confirmed');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['restaurant_table_id', 'reservation_time'], 'restaurant_reservations_table_time_index');
            $table->index(['status', 'reservation_time']);
        });

        Schema::create('modifier_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('selection_type', 8);
            $table->boolean('is_required')->default(false);
            $table->timestamps();
        });

        Schema::create('modifier_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();
            $table->string('name', 80);
            $table->decimal('price_adjustment', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_modifier_group', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained('modifier_groups')->cascadeOnDelete();

            $table->unique(['product_id', 'modifier_group_id']);
        });

        Schema::create('order_item_modifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('modifier_option_id')->nullable()->constrained('modifier_options')->nullOnDelete();
            $table->string('name_snapshot', 160);
            $table->decimal('price_adjustment_snapshot', 18, 4);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreign('restaurant_table_id')->references('id')->on('restaurant_tables')->nullOnDelete();
            $table->index(['restaurant_table_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['restaurant_table_id']);
            $table->dropIndex(['restaurant_table_id', 'status']);
        });
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('product_modifier_group');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('restaurant_reservations');
        Schema::dropIfExists('restaurant_tables');
        Schema::dropIfExists('restaurant_floors');
    }
};
