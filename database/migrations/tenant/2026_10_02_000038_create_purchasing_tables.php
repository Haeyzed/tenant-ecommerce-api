<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Suppliers and purchasing (spec §49). Quantities are decimal(15,3) like
| inventory; money is decimal(18,4); rates are decimal(24,12) like the rest
| of multi-currency (1 record currency = rate base). Additions to §49.2:
| po_number (the purchase_order.received template names it) and the
| submitted/received/cancelled timestamps; supplier_quotations keep the
| purchase order an accepted quote became.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('contact_name', 160)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address_line')->nullable();
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('payment_terms', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        Schema::create('supplier_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('supplier_sku', 64)->nullable();
            $table->decimal('cost_price', 18, 4)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'product_id']);
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('po_number', 32)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate_used', 24, 12)->nullable();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'order_date']);
            $table->index('supplier_id');
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity_ordered', 15, 3);
            $table->decimal('quantity_received', 15, 3)->default(0);
            $table->decimal('unit_cost', 18, 4);
            $table->timestamps();
        });

        Schema::create('quotation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->text('notes')->nullable();
            $table->date('respond_by')->nullable();
            $table->dateTime('requested_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('quotation_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_request_id')->constrained('quotation_requests')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity_requested', 15, 3);
            $table->timestamps();
        });

        Schema::create('supplier_quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quotation_request_id')->constrained('quotation_requests')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->timestamps();

            $table->unique(['quotation_request_id', 'supplier_id']);
            $table->index(['status', 'valid_until']);
        });

        Schema::create('supplier_quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_quotation_id')->constrained('supplier_quotations')->cascadeOnDelete();
            $table->foreignId('quotation_request_item_id')->constrained('quotation_request_items')->cascadeOnDelete();
            $table->decimal('unit_price', 18, 4);
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->timestamps();

            $table->unique(['supplier_quotation_id', 'quotation_request_item_id'], 'supplier_quotation_items_line_unique');
        });

        Schema::create('purchase_return_reasons', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('purchase_return_reason_id')->constrained('purchase_return_reasons')->restrictOnDelete();
            $table->string('status', 24)->default('requested');
            $table->string('resolution', 24)->nullable();
            $table->text('note')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['purchase_order_id', 'status']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('purchase_return_id')->nullable()->constrained('purchase_returns')->restrictOnDelete();
            $table->decimal('amount_due', 18, 4)->nullable();
            $table->decimal('amount_received', 18, 4)->nullable();
            $table->decimal('amount_paid', 18, 4);
            $table->decimal('change_given', 18, 4)->nullable();
            $table->string('payment_method', 24);
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->decimal('exchange_rate_used', 24, 12)->nullable();
            $table->dateTime('paid_at');
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['supplier_id', 'paid_at']);
            $table->index('purchase_order_id');
        });

        // The constraint expenses.supplier_id waited for (§57.4).
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
        });

        foreach (['supplier_payments', 'purchase_return_items', 'purchase_returns', 'purchase_return_reasons', 'supplier_quotation_items',
            'supplier_quotations', 'quotation_request_items', 'quotation_requests', 'purchase_order_items', 'purchase_orders',
            'supplier_products', 'suppliers'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
