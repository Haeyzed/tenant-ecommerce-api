<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Project management (§63.1). Additions: projects.completed_at and
| project_tasks.completed_at (set when the status becomes completed, for
| the "completed in the range" figures of §44.3 and §44.4).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('status', 8)->default('active');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignId('project_category_id')->constrained('project_categories')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('priority', 8)->default('medium');
            $table->string('status', 12)->default('not_started');
            $table->boolean('notify_assigned_employees_whatsapp')->default(false);
            $table->boolean('notify_customer_whatsapp')->default(false);
            $table->text('description')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index('customer_id');
        });

        Schema::create('project_user', function (Blueprint $table): void {
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('project_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('not_started');
            $table->boolean('send_whatsapp_notification')->default(false);
            $table->text('description')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['assigned_user_id', 'status']);
            $table->index(['status', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('project_categories');
    }
};
