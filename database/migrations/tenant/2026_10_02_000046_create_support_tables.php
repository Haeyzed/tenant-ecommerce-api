<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Customer support (§59.1): tickets and live chat share one conversation
| and message model. Addition: support_conversations.index on
| (assigned_to_user_id, status) for the "my conversations" inbox.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->string('guest_token', 64)->nullable()->index();
            $table->string('guest_name', 120)->nullable();
            $table->string('guest_email')->nullable();
            $table->string('channel', 8);
            $table->string('subject')->nullable();
            $table->string('status', 10)->default('open');
            $table->string('priority', 8)->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'channel']);
            $table->index('customer_id');
            $table->index(['assigned_to_user_id', 'status']);
            $table->index('last_message_at');
        });

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->string('sender_type', 8);
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('body');
            $table->boolean('is_internal_note')->default(false);
            $table->dateTime('read_at')->nullable();
            $table->timestamps();

            $table->index(['support_conversation_id', 'id']);
        });

        Schema::create('support_message_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_message_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_conversations');
    }
};
