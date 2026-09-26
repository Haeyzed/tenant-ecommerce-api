<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: shipments, their items and in-house delivery assignments
 * (§36.2). A shipment ships from one warehouse; a line's quantity is never
 * over-allocated across non-cancelled shipments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('shipping_method_id')->nullable()->constrained('shipping_methods')->restrictOnDelete();
            $table->string('fulfillment_type', 16);
            $table->string('carrier', 64)->nullable();
            $table->string('tracking_number', 128)->nullable();
            $table->string('tracking_url')->nullable();
            $table->string('status', 16)->default('pending');
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('order_id');
            $table->index('status');
        });

        Schema::create('shipment_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);

            $table->unique(['shipment_id', 'order_item_id']);
        });

        Schema::create('delivery_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->restrictOnDelete();
            $table->string('status', 16);
            $table->dateTime('assigned_at');
            $table->dateTime('picked_up_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->text('delivery_notes')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_assignments');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
    }
};
