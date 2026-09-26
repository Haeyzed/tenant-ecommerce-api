<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord notification templates, channel matrix and inbox (§17.2).
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

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_template_channels');
        Schema::dropIfExists('notification_templates');
    }
};
