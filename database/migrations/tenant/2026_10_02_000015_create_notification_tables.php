<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant group 4 (spec §79.2): notification templates, channel matrix and
 * recipient preferences (§17.2). push_device_tokens is created by Messaging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 128)->unique();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->json('target_audience');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_customized')->default(false);
            $table->timestamps();
        });

        Schema::create('notification_template_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_template_id')->constrained('notification_templates')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['notification_template_id', 'channel'], 'notification_template_channels_unique');
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->string('notifiable_type', 32);
            $table->unsignedBigInteger('notifiable_id');
            $table->string('template_key', 128)->nullable();
            $table->string('channel', 16);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'template_key', 'channel'], 'notification_preferences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_template_channels');
        Schema::dropIfExists('notification_templates');
    }
};
