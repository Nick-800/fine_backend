<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 09: the pay side of an employee. labor_role keys into the
        // versioned labor_role_rates; monthly_salary is the base for salaried
        // staff; hourly_rate is a personal fallback when no role rate exists.
        Schema::table('employees', function (Blueprint $table) {
            $table->string('labor_role', 100)->nullable()->after('job_title');
            $table->decimal('monthly_salary', 15, 4)->nullable()->after('pay_type');
            $table->decimal('hourly_rate', 15, 4)->nullable()->after('monthly_salary');
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->date('work_date');
            $table->string('status', 20); // present | absent | leave | half_day
            $table->decimal('hours_worked', 5, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['operating_unit_id', 'work_date']);
        });

        // HR-04: rates are versioned, never edited — a new row supersedes.
        Schema::create('labor_role_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('role', 100);
            $table->decimal('hourly_rate', 15, 4);
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['role', 'effective_from']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('period', 7)->unique(); // YYYY-MM, one run per period
            $table->string('status', 30)->default('draft');
            $table->decimal('total_gross', 15, 4)->default(0);
            $table->decimal('total_deductions', 15, 4)->default(0);
            $table->decimal('total_net', 15, 4)->default(0);
            $table->foreignUuid('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->decimal('base_pay', 15, 4)->default(0);
            $table->decimal('attendance_pay', 15, 4)->default(0);
            // Portion already accrued into 2200 by production order postings —
            // payroll settles it instead of expensing it twice.
            $table->decimal('labor_log_pay', 15, 4)->default(0);
            $table->decimal('gross_pay', 15, 4);
            $table->json('deductions')->nullable();
            $table->decimal('net_pay', 15, 4);
            $table->foreignUuid('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('operating_unit_id')->constrained('operating_units')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('leave_type', 30); // annual | sick | unpaid | other
            $table->string('reason')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('labor_role_rates');
        Schema::dropIfExists('attendances');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['labor_role', 'monthly_salary', 'hourly_rate']);
        });
    }
};
