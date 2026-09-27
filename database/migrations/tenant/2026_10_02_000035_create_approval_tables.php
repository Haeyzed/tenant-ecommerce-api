<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant: the approval workflow engine (spec §60.2): workflow configuration
 * (workflows, steps, approvers) and runtime (requests, actions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflows', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('module_key', 40);
            $table->json('trigger_conditions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['module_key', 'is_active']);
        });

        Schema::create('approval_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_workflow_id')->constrained('approval_workflows')->cascadeOnDelete();
            $table->unsignedInteger('step_order');
            $table->string('name', 120);
            $table->string('approval_mode', 8)->default('any_one');
            $table->timestamps();

            $table->unique(['approval_workflow_id', 'step_order']);
        });

        Schema::create('approval_step_approvers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_step_id')->constrained('approval_steps')->cascadeOnDelete();
            $table->string('approver_type', 8);
            $table->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_workflow_id')->constrained('approval_workflows')->restrictOnDelete();
            $table->string('approvable_type', 64);
            $table->unsignedBigInteger('approvable_id');
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('current_step_order')->default(1);
            $table->string('status', 12)->default('pending');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id']);
            $table->index(['status', 'approval_workflow_id']);
        });

        Schema::create('approval_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('approval_requests')->cascadeOnDelete();
            // Steps may be removed from a workflow with no pending requests;
            // past actions keep their step id and name snapshot.
            $table->unsignedBigInteger('approval_step_id');
            $table->string('step_name', 120);
            $table->unsignedInteger('step_order');
            $table->foreignId('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 10);
            $table->text('note')->nullable();
            $table->dateTime('decided_at');
            $table->timestamps();

            $table->unique(['approval_request_id', 'approval_step_id', 'approved_by_user_id'], 'approval_actions_request_step_user_unique');
        });
    }

    public function down(): void
    {
        foreach (['approval_actions', 'approval_requests', 'approval_step_approvers', 'approval_steps', 'approval_workflows'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
