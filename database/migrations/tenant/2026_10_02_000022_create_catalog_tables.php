<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: the catalogue (§27, §28, §29). Stock is never a product column;
 * it lives in inventory (§32). seller_id references sellers, created with
 * the Marketplace module, which adds the foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('short_code', 16)->unique();
            $table->boolean('allows_decimal')->default(false);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('meta_keywords')->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('meta_keywords')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('product_type', 16)->default('simple');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku', 64)->nullable()->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->longText('description')->nullable();
            $table->decimal('price', 18, 4);
            $table->decimal('compare_at_price', 18, 4)->nullable();
            $table->decimal('cost_price', 18, 4)->nullable();
            $table->string('tax_class', 16)->default('standard');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('moderation_status', 16)->default('not_required');
            $table->string('moderation_note')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained('units_of_measure')->restrictOnDelete();
            $table->string('hsn_code', 16)->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('has_warehouse_pricing')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('view_count')->default(0);
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('meta_keywords')->nullable();
            $table->boolean('is_bookable')->default(false);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->boolean('is_subscribable')->default(false);
            $table->decimal('subscription_discount_percent', 7, 4)->nullable();
            $table->json('social_commerce_excluded_channels')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('brand_id');
            $table->index('seller_id');
            $table->index(['is_active', 'price']);
            $table->index(['is_active', 'created_at']);
        });

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);

            $table->unique(['product_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('product_options', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_option_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_option_id')->constrained('product_options')->cascadeOnDelete();
            $table->string('value', 64);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_option_id', 'value']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->decimal('price', 18, 4)->nullable();
            $table->decimal('compare_at_price', 18, 4)->nullable();
            $table->decimal('cost_price', 18, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'is_active']);
        });

        Schema::create('product_variant_option_values', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('product_option_value_id')->constrained('product_option_values')->restrictOnDelete();

            $table->unique(['product_variant_id', 'product_option_value_id'], 'pvov_variant_value_unique');
            $table->index('product_option_value_id');
        });

        Schema::create('product_bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('child_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('child_product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->timestamps();

            $table->unique(['bundle_product_id', 'child_product_id', 'child_product_variant_id'], 'bundle_items_unique');
        });

        Schema::create('digital_product_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('download_limit')->nullable();
            $table->unsignedInteger('expires_after_days')->nullable();
            $table->timestamps();
        });

        Schema::create('product_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();
            $table->string('relation_type', 16);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'related_product_id', 'relation_type'], 'product_relations_unique');
        });

        Schema::create('product_specifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('spec_group', 120)->nullable();
            $table->string('spec_key', 120);
            $table->string('spec_value');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('slug', 80)->unique();
            $table->timestamps();
        });

        Schema::create('product_tag', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();

            $table->unique(['product_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::create('product_badges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('badge_type', 16);
            $table->string('label', 60)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'badge_type']);
        });

        Schema::create('product_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->dateTime('viewed_at');

            $table->index(['product_id', 'viewed_at']);
            $table->index('viewed_at');
        });

        Schema::create('product_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->text('question');
            $table->boolean('is_approved')->default(false);
            $table->dateTime('asked_at');
            $table->timestamps();

            $table->index(['product_id', 'is_approved']);
        });

        Schema::create('product_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_question_id')->constrained('product_questions')->cascadeOnDelete();
            $table->string('answered_by_type', 16);
            $table->unsignedBigInteger('answered_by_id');
            $table->text('answer');
            $table->boolean('is_approved')->default(false);
            $table->timestamps();

            $table->index(['product_question_id', 'is_approved']);
        });
    }

    public function down(): void
    {
        foreach ([
            'product_answers', 'product_questions', 'product_views', 'product_badges', 'product_tag', 'tags',
            'product_specifications', 'product_relations', 'digital_product_files', 'product_bundle_items',
            'product_variant_option_values', 'product_variants', 'product_option_values', 'product_options',
            'product_categories', 'products', 'brands', 'categories', 'units_of_measure',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
