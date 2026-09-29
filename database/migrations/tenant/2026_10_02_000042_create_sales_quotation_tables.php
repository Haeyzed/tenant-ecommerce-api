<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Sales quotations (§53.1). Additions: sales_quotations.quotation_number
| (SQ-000001, named by the sales_quotation.sent template) and the
| quotation's currency and totals, fixed when it is sent.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quotation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('sales_agent_id')->nullable()->constrained('sales_agents')->nullOnDelete();
            $table->string('status', 12)->default('sent');
            $table->char('currency_code', 3);
            $table->text('notes')->nullable();
            $table->dateTime('requested_at');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'requested_at']);
            $table->index(['customer_id', 'requested_at']);
        });

        Schema::create('sales_quotation_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_quotation_request_id')->constrained('sales_quotation_requests')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity_requested', 15, 3);
            $table->timestamps();
        });

        Schema::create('sales_quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_quotation_request_id')->unique()->constrained('sales_quotation_requests')->cascadeOnDelete();
            $table->string('quotation_number', 16)->unique();
            $table->string('status', 10)->default('sent');
            $table->char('currency_code', 3);
            $table->decimal('subtotal', 18, 4);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->foreignId('converted_order_id')->nullable()->unique()->constrained('orders')->nullOnDelete();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'valid_until']);
            $table->index('sent_at');
        });

        Schema::create('sales_quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_quotation_id')->constrained('sales_quotations')->cascadeOnDelete();
            $table->foreignId('sales_quotation_request_item_id')->constrained('sales_quotation_request_items')->cascadeOnDelete();
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 4)->nullable();
            $table->timestamps();

            $table->unique(['sales_quotation_id', 'sales_quotation_request_item_id'], 'sales_quotation_items_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quotation_items');
        Schema::dropIfExists('sales_quotations');
        Schema::dropIfExists('sales_quotation_request_items');
        Schema::dropIfExists('sales_quotation_requests');
    }
};
