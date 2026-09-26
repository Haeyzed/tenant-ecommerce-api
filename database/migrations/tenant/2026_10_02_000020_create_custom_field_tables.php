<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: custom_field_definitions and custom_field_values (§23.2). One
 * typed value column per row keeps filters and sorting correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 48);
            $table->string('key', 64);
            $table->string('label');
            $table->string('description')->nullable();
            $table->string('placeholder')->nullable();
            $table->string('field_type', 24);
            $table->json('options')->nullable();
            $table->string('display', 16)->nullable();
            $table->json('default_value')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_admin_only')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('validation')->nullable();
            $table->boolean('show_in_table')->default(false);
            $table->boolean('show_on_form')->default(true);
            $table->boolean('show_on_detail')->default(true);
            $table->boolean('show_on_documents')->default(false);
            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['entity_type', 'key']);
            $table->index(['entity_type', 'is_active', 'sort_order']);
        });

        Schema::create('custom_field_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('custom_field_definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();
            $table->string('entity_type', 48);
            $table->unsignedBigInteger('entity_id');
            $table->string('value_string')->nullable();
            $table->text('value_text')->nullable();
            $table->decimal('value_decimal', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->dateTime('value_datetime')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['custom_field_definition_id', 'entity_id'], 'custom_field_values_definition_entity_unique');
            $table->index(['entity_type', 'entity_id']);
            $table->index(['custom_field_definition_id', 'value_string'], 'cfv_string_index');
            $table->index(['custom_field_definition_id', 'value_decimal'], 'cfv_decimal_index');
            $table->index(['custom_field_definition_id', 'value_date'], 'cfv_date_index');
            $table->index(['custom_field_definition_id', 'value_datetime'], 'cfv_datetime_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_definitions');
    }
};
