<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| HR shifts, the roster and overtime (spec §58.3a, D-140). Expand-only:
| existing attendance keeps working against hr_settings, and overtime is
| off until a tenant enables it.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->time('start_time');
            // Earlier than start_time: the shift ends the next day.
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            // Null: the employee's applicable hr_settings grace.
            $table->unsignedSmallInteger('late_grace_minutes')->nullable();
            $table->unsignedSmallInteger('early_leave_grace_minutes')->nullable();
            $table->string('color', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_shift_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('hr_shifts')->restrictOnDelete();
            // The day the shift starts, in the tenant timezone.
            $table->date('work_date');
            $table->string('notes')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['work_date', 'shift_id']);
        });

        Schema::table('hr_attendance', function (Blueprint $table): void {
            $table->foreignId('shift_id')->nullable()->after('work_date')->constrained('hr_shifts')->restrictOnDelete();
            $table->unsignedInteger('scheduled_minutes')->nullable()->after('is_early_leave');
            $table->unsignedInteger('worked_minutes')->nullable()->after('scheduled_minutes');
            $table->unsignedInteger('overtime_minutes')->default(0)->after('worked_minutes');
            // none | pending | approved | rejected
            $table->string('overtime_status', 10)->default('none')->after('overtime_minutes');
            $table->unsignedInteger('overtime_approved_minutes')->nullable()->after('overtime_status');
            $table->foreignId('overtime_decided_by_user_id')->nullable()->after('overtime_approved_minutes')->constrained('users')->nullOnDelete();
            $table->dateTime('overtime_decided_at')->nullable()->after('overtime_decided_by_user_id');
            $table->string('overtime_note')->nullable()->after('overtime_decided_at');

            $table->index(['overtime_status', 'work_date']);
        });

        Schema::table('hr_settings', function (Blueprint $table): void {
            $table->boolean('overtime_enabled')->default(false);
            // Extra minutes below this are not overtime.
            $table->unsignedSmallInteger('overtime_minimum_minutes')->default(30);
            $table->decimal('overtime_rate_multiplier', 5, 2)->default(1.5);
            // Turns a monthly salary into an hourly rate.
            $table->decimal('standard_monthly_hours', 6, 2)->default(173.33);
        });

        Schema::table('hr_salary_structures', function (Blueprint $table): void {
            // Null: base_salary ÷ standard_monthly_hours.
            $table->decimal('hourly_rate', 18, 4)->nullable()->after('base_salary');
        });
    }

    public function down(): void
    {
        Schema::table('hr_salary_structures', function (Blueprint $table): void {
            $table->dropColumn('hourly_rate');
        });

        Schema::table('hr_settings', function (Blueprint $table): void {
            $table->dropColumn(['overtime_enabled', 'overtime_minimum_minutes', 'overtime_rate_multiplier', 'standard_monthly_hours']);
        });

        Schema::table('hr_attendance', function (Blueprint $table): void {
            $table->dropIndex(['overtime_status', 'work_date']);
            $table->dropConstrainedForeignId('overtime_decided_by_user_id');
            $table->dropConstrainedForeignId('shift_id');
            $table->dropColumn(['scheduled_minutes', 'worked_minutes', 'overtime_minutes', 'overtime_status', 'overtime_approved_minutes', 'overtime_decided_at', 'overtime_note']);
        });

        Schema::dropIfExists('hr_shift_assignments');
        Schema::dropIfExists('hr_shifts');
    }
};
