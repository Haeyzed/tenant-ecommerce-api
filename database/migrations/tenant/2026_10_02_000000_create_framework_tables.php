<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant group 1 and 2 (spec §79.2): framework and package tables, password
 * brokers, and platform infrastructure (idempotency, exports, webhooks).
 * The spatie permission tables are in their own migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('audits', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('event');
            $table->morphs('auditable');
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'user_type']);
        });

        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->morphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();
            $table->nullableTimestamps();
        });

        foreach (['password_reset_tokens', 'customer_password_reset_tokens', 'seller_password_reset_tokens'] as $broker) {
            Schema::create($broker, function (Blueprint $table): void {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 128);
            $table->string('route', 191);
            $table->string('key', 128);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->dateTime('locked_until');
            $table->dateTime('created_at');
            $table->dateTime('expires_at');

            $table->unique(['scope', 'route', 'key']);
            $table->index('expires_at');
        });

        Schema::create('data_exports', function (Blueprint $table): void {
            $table->id();
            $table->string('export_type', 64);
            $table->json('parameters');
            $table->string('format', 8);
            $table->string('status', 16)->default('queued');
            $table->string('requested_by_type', 32);
            $table->unsignedBigInteger('requested_by_id');
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['requested_by_type', 'requested_by_id']);
            $table->index('status');
        });

        Schema::create('webhook_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('mode', 8);
            $table->string('provider_event_id');
            $table->string('event_type')->nullable();
            $table->string('provider_reference')->nullable();
            $table->longText('payload');
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['mode', 'provider', 'provider_event_id']);
            $table->index(['provider', 'provider_reference']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        foreach ([
            'webhook_logs', 'data_exports', 'idempotency_keys', 'seller_password_reset_tokens',
            'customer_password_reset_tokens', 'password_reset_tokens', 'media', 'audits', 'activity_log',
            'notifications', 'personal_access_tokens',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
