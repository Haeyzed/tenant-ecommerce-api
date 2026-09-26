<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: invoice templates, barcode sticker layouts, receipt printers
 * (§43) and digital download grants (§28.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->string('invoice_type', 8)->default('a4');
            $table->boolean('is_default')->default(false);
            $table->string('prefix', 20)->nullable();
            $table->string('numbering_type', 16)->default('sequential');
            $table->unsignedInteger('start_number')->default(1);
            $table->unsignedInteger('last_number')->nullable();
            $table->text('header_text')->nullable();
            $table->text('footer_text')->nullable();
            $table->text('payment_notes')->nullable();
            $table->text('sales_notes')->nullable();
            $table->unsignedBigInteger('logo_media_id')->nullable();
            $table->decimal('logo_height', 8, 2)->nullable();
            $table->decimal('logo_width', 8, 2)->nullable();
            $table->string('primary_color', 9)->nullable();
            $table->string('date_format', 20)->nullable();
            foreach ([
                'show_barcode' => false, 'show_qr_code' => false, 'show_description' => true, 'show_amount_in_words' => false,
                'show_warehouse_info' => false, 'show_bill_to_info' => true, 'show_payment_details' => true, 'show_payment_notes' => false,
                'show_reference_number' => false, 'show_generated_invoice_number' => true, 'show_total_due' => true,
                'show_registration_number' => false, 'show_sales_notes' => false, 'show_customer_name' => true, 'signature_enabled' => false,
            ] as $column => $default) {
                $table->boolean($column)->default($default);
            }
            $table->timestamps();
        });

        Schema::create('barcode_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->string('label_layout', 16)->default('continuous');
            $table->boolean('is_default')->default(false);
            foreach (['top_margin_inches', 'left_margin_inches', 'sticker_width_inches', 'sticker_height_inches', 'paper_width_inches',
                'paper_height_inches', 'row_distance_inches', 'column_distance_inches'] as $column) {
                $table->decimal($column, 6, 3);
            }
            $table->unsignedInteger('stickers_per_row');
            $table->unsignedInteger('stickers_per_sheet');
            $table->timestamps();
        });

        Schema::create('receipt_printers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('connection_type', 16);
            $table->string('capability_profile', 40)->default('default');
            $table->unsignedSmallInteger('characters_per_line')->default(42);
            $table->string('ip_address', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['warehouse_id', 'is_active']);
        });

        Schema::create('digital_download_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('digital_product_file_id')->constrained('digital_product_files')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('download_limit')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['order_item_id', 'digital_product_file_id'], 'download_grants_item_file_unique');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        foreach (['digital_download_grants', 'receipt_printers', 'barcode_settings', 'invoice_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
