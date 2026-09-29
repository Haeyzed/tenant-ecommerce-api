<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| AI business assistant (§62.1). Addition: an index on
| ai_assistant_query_logs (matched_intent_key, created_at), so unmatched
| questions are listed quickly.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_intents', function (Blueprint $table): void {
            $table->id();
            $table->string('intent_key', 64)->unique();
            $table->json('sample_phrases');
            $table->string('handler', 64);
            $table->string('required_feature', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_assistant_query_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('raw_query');
            $table->string('matched_intent_key', 64)->nullable();
            $table->string('response_summary')->nullable();
            $table->dateTime('created_at');

            $table->index(['matched_intent_key', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_query_logs');
        Schema::dropIfExists('ai_assistant_intents');
    }
};
