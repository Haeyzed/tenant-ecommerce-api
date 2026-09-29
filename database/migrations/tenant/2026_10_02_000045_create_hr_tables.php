<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Human resources (§58), with the payroll (§58.5) and recruitment (§58.7)
| tables. Inside the module, foreign keys drop the hr_ prefix. Additions:
| hr_settings.scope_key (one tenant-wide row and one per department as a
| database rule), hr_leave_requests.requested_by_user_id, and
| hr_payroll_runs.created_by_user_id.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_departments', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->string('employee_number', 32)->nullable()->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('employment_type', 16);
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->string('status', 10)->default('active');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'department_id']);
        });

        Schema::create('hr_document_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->boolean('requires_expiry_date')->default(false);
            $table->boolean('is_mandatory_at_onboarding')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_employee_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained('hr_document_types')->restrictOnDelete();
            $table->date('expiry_date')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('expiry_date');
        });

        Schema::create('hr_settings', function (Blueprint $table): void {
            $table->id();
            // Restrict: MySQL forbids cascades on the base column of a stored generated column.
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->restrictOnDelete();
            $table->unsignedBigInteger('scope_key')->storedAs('IFNULL(`department_id`, 0)')->unique();
            $table->time('expected_clock_in_time');
            $table->time('expected_clock_out_time');
            $table->unsignedSmallInteger('late_grace_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_grace_minutes')->default(0);
            $table->timestamps();
        });

        Schema::create('hr_attendance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->date('work_date');
            $table->dateTime('clock_in_at');
            $table->dateTime('clock_out_at')->nullable();
            $table->boolean('is_late')->default(false);
            $table->boolean('is_early_leave')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index('work_date');
        });

        Schema::create('hr_leave_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->decimal('days_per_year', 5, 1);
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_leave_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('entitled_days', 5, 1);
            $table->decimal('used_days', 5, 1)->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        Schema::create('hr_leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 5, 1);
            $table->text('reason')->nullable();
            $table->string('status', 10)->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'start_date']);
            $table->index(['employee_id', 'start_date']);
        });

        Schema::create('hr_salary_structures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->decimal('base_salary', 18, 4);
            $table->char('currency_code', 3);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });

        Schema::create('hr_payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 12)->default('draft');
            $table->date('run_date');
            $table->decimal('total_gross', 18, 4)->default(0);
            $table->decimal('total_deductions', 18, 4)->default(0);
            $table->decimal('total_net', 18, 4)->default(0);
            $table->dateTime('finalized_at')->nullable();
            $table->foreignId('finalized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'period_start']);
        });

        Schema::create('hr_payroll_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->decimal('base_salary', 18, 4);
            $table->decimal('total_allowances', 18, 4)->default(0);
            $table->decimal('gross_pay', 18, 4)->default(0);
            $table->decimal('total_deductions', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('net_pay', 18, 4)->default(0);
            $table->string('status', 8)->default('pending');
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('hr_payroll_item_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_item_id')->constrained('hr_payroll_items')->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('label', 120);
            $table->decimal('amount', 18, 4);
            $table->boolean('is_percentage')->default(false);
            $table->decimal('percentage', 7, 4)->nullable();
            $table->timestamps();
        });

        Schema::create('hr_appraisal_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_appraisal_template_criteria', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appraisal_template_id')->constrained('hr_appraisal_templates')->cascadeOnDelete();
            $table->string('label', 120);
            $table->decimal('weight', 7, 4);
            $table->decimal('max_score', 5, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('hr_appraisals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('appraisal_template_id')->constrained('hr_appraisal_templates')->restrictOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 14)->default('draft');
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->text('overall_comments')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'period_start']);
        });

        Schema::create('hr_appraisal_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appraisal_id')->constrained('hr_appraisals')->cascadeOnDelete();
            $table->foreignId('appraisal_template_criterion_id')->constrained('hr_appraisal_template_criteria')->restrictOnDelete();
            $table->decimal('score', 5, 2);
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->unique(['appraisal_id', 'appraisal_template_criterion_id'], 'hr_appraisal_scores_criterion_unique');
        });

        Schema::create('hr_job_postings', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->string('slug', 190)->unique();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->text('description');
            $table->text('requirements')->nullable();
            $table->string('location', 120)->nullable();
            $table->string('employment_type', 16);
            $table->string('status', 8)->default('draft');
            $table->date('application_deadline')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'posted_at']);
        });

        Schema::create('hr_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('source', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('hr_job_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_posting_id')->constrained('hr_job_postings')->restrictOnDelete();
            $table->foreignId('candidate_id')->constrained('hr_candidates')->restrictOnDelete();
            $table->text('cover_letter')->nullable();
            $table->string('status', 14)->default('applied');
            $table->dateTime('status_changed_at')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('applied_at');
            $table->foreignId('converted_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['job_posting_id', 'candidate_id']);
            $table->index(['status', 'applied_at']);
        });
    }

    public function down(): void
    {
        foreach (['hr_job_applications', 'hr_candidates', 'hr_job_postings', 'hr_appraisal_scores', 'hr_appraisals', 'hr_appraisal_template_criteria',
            'hr_appraisal_templates', 'hr_payroll_item_lines', 'hr_payroll_items', 'hr_payroll_runs', 'hr_salary_structures', 'hr_leave_requests',
            'hr_leave_balances', 'hr_leave_types', 'hr_attendance', 'hr_settings', 'hr_employee_documents', 'hr_document_types', 'hr_employees', 'hr_departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
