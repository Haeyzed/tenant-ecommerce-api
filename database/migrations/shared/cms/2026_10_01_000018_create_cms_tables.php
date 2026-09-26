<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared CMS tables (§24). This migration runs in the landlord database and
 * in every tenant database; the two copies never share data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('system_key', 64)->nullable()->unique();
            $table->boolean('is_homepage')->default(false);
            $table->longText('body')->nullable();
            $table->string('status', 16)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('canonical_url')->nullable();
            $table->string('robots', 24)->default('index_follow');
            $table->timestamps();
        });

        Schema::create('cms_page_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cms_page_id')->constrained('cms_pages')->cascadeOnDelete();
            $table->string('section_type', 48);
            $table->json('settings');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['cms_page_id', 'sort_order']);
        });

        Schema::create('cms_menus', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('cms_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cms_menu_id')->constrained('cms_menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('cms_menu_items')->cascadeOnDelete();
            $table->string('label');
            $table->string('link_type', 16);
            $table->unsignedBigInteger('linkable_id')->nullable();
            $table->string('url')->nullable();
            $table->boolean('open_in_new_tab')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('cms_banners', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('title');
            $table->string('body', 200)->nullable();
            $table->string('link_url')->nullable();
            $table->string('link_label')->nullable();
            $table->string('position', 64)->nullable();
            $table->string('background_color', 7)->nullable();
            $table->string('text_color', 7)->nullable();
            $table->boolean('is_dismissible')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('cms_blog_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cms_blog_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cms_blog_category_id')->nullable()->constrained('cms_blog_categories')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('excerpt')->nullable();
            $table->longText('body');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name');
            $table->string('status', 16)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('canonical_url')->nullable();
            $table->timestamps();
        });

        Schema::create('cms_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('cms_blog_post_tag', function (Blueprint $table): void {
            $table->foreignId('cms_blog_post_id')->constrained('cms_blog_posts')->cascadeOnDelete();
            $table->foreignId('cms_tag_id')->constrained('cms_tags')->cascadeOnDelete();
            $table->primary(['cms_blog_post_id', 'cms_tag_id']);
        });

        Schema::create('cms_faq_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cms_faqs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cms_faq_category_id')->nullable()->constrained('cms_faq_categories')->nullOnDelete();
            $table->string('question');
            $table->longText('answer');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cms_testimonials', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_name');
            $table->string('customer_title')->nullable();
            $table->text('quote');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('contact_submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('subject')->nullable();
            $table->text('message');
            $table->string('status', 16)->default('new');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('handled_by_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
        Schema::dropIfExists('cms_testimonials');
        Schema::dropIfExists('cms_faqs');
        Schema::dropIfExists('cms_faq_categories');
        Schema::dropIfExists('cms_blog_post_tag');
        Schema::dropIfExists('cms_tags');
        Schema::dropIfExists('cms_blog_posts');
        Schema::dropIfExists('cms_blog_categories');
        Schema::dropIfExists('cms_banners');
        Schema::dropIfExists('cms_menu_items');
        Schema::dropIfExists('cms_menus');
        Schema::dropIfExists('cms_page_sections');
        Schema::dropIfExists('cms_pages');
    }
};
