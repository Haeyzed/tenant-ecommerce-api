<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Repair (§67.1). Parts leave stock when fitted; the invoice lines carry
| stock_already_deducted so the order never moves them again.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('item_description', 255);
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 20)->default('received');
            $table->text('diagnosis_notes')->nullable();
            $table->decimal('estimated_cost', 18, 4)->nullable();
            $table->dateTime('customer_approved_at')->nullable();
            $table->foreignId('order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('received_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('picked_up_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index(['customer_id', 'received_at']);
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('repair_job_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_job_id')->constrained('repair_jobs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost_snapshot', 18, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('repair_job_labor', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repair_job_id')->constrained('repair_jobs')->cascadeOnDelete();
            $table->string('description', 255);
            $table->decimal('amount', 18, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_job_labor');
        Schema::dropIfExists('repair_job_parts');
        Schema::dropIfExists('repair_jobs');
    }
};
