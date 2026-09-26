<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord: platform support helpdesk (§21.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_support_conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('raised_by_user_id');
            $table->string('raised_by_name');
            $table->string('raised_by_email');
            $table->string('subject');
            $table->string('category', 32);
            $table->string('status', 16)->default('open');
            $table->string('priority', 16)->default('normal');
            $table->foreignId('assigned_to')->nullable()->constrained('platform_users');
            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('platform_support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_support_conversation_id')->constrained('platform_support_conversations', indexName: 'ps_messages_conversation_fk')->cascadeOnDelete();
            $table->string('sender_type', 16);
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('sender_label');
            $table->text('body');
            $table->boolean('is_internal_note')->default(false);
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_support_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_support_message_id')->constrained('platform_support_messages', indexName: 'ps_attachments_message_fk')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_support_message_attachments');
        Schema::dropIfExists('platform_support_messages');
        Schema::dropIfExists('platform_support_conversations');
    }
};
