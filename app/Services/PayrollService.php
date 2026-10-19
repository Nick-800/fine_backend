<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PayrollRunStatus;
use App\Enums\PayType;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LaborRoleRate;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Support\TreasuryAccounts;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 09 §9.4–9.7. A run opens a period, aggregates attendance into
 * payslips (§9.6), passes review and approval gates (HR-06), and posts to
 * the ledger on the final transition (HR-08).
 */
final class PayrollService
{
    /** Withholdings owed on to the tax office / social security until remitted. */
    public const DEDUCTIONS_PAYABLE = '225';

    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    public function open(string $period, ?string $userId = null): PayrollRun
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException("Period must be YYYY-MM, got \"{$period}\".");
        }

        if (PayrollRun::withTrashed()->where('period', $period)->exists()) {
            throw new InvalidArgumentException("A payroll run for {$period} already exists.");
        }

        $companyId = Company::query()->value('id');

        if ($companyId === null) {
            throw new InvalidArgumentException('No company exists to run payroll for.');
        }

        return PayrollRun::create([
            'company_id' => $companyId,
            'period' => $period,
            'status' => PayrollRunStatus::Draft,
            'opened_by_user_id' => $userId,
        ]);
    }

    /**
     * §9.6: gross = base salary + payable attendance hours × role rate.
     * Recalculating while still in Calculated wipes and regenerates the
     * payslips.
     */
    public function calculate(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run = PayrollRun::lockForUpdate()->findOrFail($run->id);

            $this->guardTransition($run, PayrollRunStatus::Calculated);

            $run->payslips()->delete();

            $start = Carbon::createFromFormat('Y-m', $run->period)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $employees = Employee::withoutGlobalScopes()
                ->where('status', '!=', 'terminated')
                ->get();

            $totalGross = 0.0;

            foreach ($employees as $employee) {
                $basePay = $employee->pay_type === PayType::Monthly
                    ? $this->monthlyBasePay($employee, $start, $end)
                    : 0.0;

                $attendancePay = $employee->pay_type === PayType::Hourly
                    ? $this->attendancePay($employee, $start, $end)
                    : 0.0;

                $gross = round($basePay + $attendancePay, 4);

                if ($gross <= 0.0) {
                    continue;
                }

                Payslip::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'operating_unit_id' => $employee->operating_unit_id,
                    'base_pay' => $basePay,
                    'attendance_pay' => $attendancePay,
                    'labor_log_pay' => 0,
                    'gross_pay' => $gross,
                    'deductions' => [],
                    'net_pay' => $gross,
                ]);

                $totalGross = round($totalGross + $gross, 4);
            }

            $run->update([
                'status' => PayrollRunStatus::Calculated,
                'total_gross' => $totalGross,
                'total_deductions' => 0,
                'total_net' => $totalGross,
            ]);

            return $run->refresh()->load('payslips.employee.entity');
        });
    }

    /**
     * BR-PAY-004: a salaried employee hired inside the period is paid only for
     * the calendar days they were employed (the hire day counts as worked);
     * one hired after the period has not earned anything yet.
     */
    private function monthlyBasePay(Employee $employee, Carbon $start, Carbon $end): float
    {
        $salary = (float) ($employee->monthly_salary ?? 0);
        $hireDate = $employee->hire_date;

        if ($hireDate === null || $hireDate->lte($start)) {
            return round($salary, 4);
        }

        if ($hireDate->gt($end)) {
            return 0.0;
        }

        $daysEmployed = $end->day - $hireDate->day + 1;

        return round($salary * $daysEmployed / $start->daysInMonth, 4);
    }

    /**
     * HR-07: deductions are extensible line items; net is derived, never
     * accepted from the caller. Only while the run sits in Calculated.
     *
     * @param  list<array{type: string, amount: float}>  $deductions
     */
    public function setDeductions(Payslip $payslip, array $deductions): Payslip
    {
        return DB::transaction(function () use ($payslip, $deductions) {
            $run = PayrollRun::lockForUpdate()->findOrFail($payslip->payroll_run_id);

            if ($run->status !== PayrollRunStatus::Calculated) {
                throw new InvalidStateTransitionException(
                    'Deductions can only change while the run is in review (calculated).'
                );
            }

            $total = 0.0;

            foreach ($deductions as $deduction) {
                $amount = round((float) $deduction['amount'], 4);

                if ($amount < 0) {
                    throw new InvalidArgumentException('A deduction cannot be negative.');
                }

                $total = round($total + $amount, 4);
            }

            $net = round((float) $payslip->gross_pay - $total, 4);

            if ($net < 0) {
                throw new InvalidArgumentException(
                    'Deductions exceed gross pay; the payslip would go negative.'
                );
            }

            $payslip->update(['deductions' => $deductions, 'net_pay' => $net]);

            $run->update([
                'total_deductions' => round((float) $run->payslips()->get()->sum(fn (Payslip $p) => $p->totalDeductions()), 4),
                'total_net' => round((float) $run->payslips()->sum('net_pay'), 4),
            ]);

            return $payslip->refresh();
        });
    }

    /**
     * @param  string|null  $cashAccountId  the treasury net pay leaves from; required to post
     */
    public function transition(PayrollRun $run, PayrollRunStatus $target, ?string $userId = null, ?string $cashAccountId = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $target, $userId, $cashAccountId) {
            $run = PayrollRun::lockForUpdate()->findOrFail($run->id);

            if ($target === PayrollRunStatus::Calculated) {
                throw new InvalidArgumentException('Calculation goes through calculate(), not a bare transition.');
            }

            $this->guardTransition($run, $target);

            if ($target === PayrollRunStatus::PendingApproval && $run->payslips()->count() === 0) {
                throw new InvalidArgumentException('An empty payroll run has nothing to approve.');
            }

            if ($target === PayrollRunStatus::Approved) {
                $run->approved_by_user_id = $userId;
            }

            if ($target === PayrollRunStatus::Posted) {
                $this->postJournal($run, $cashAccountId);
            }

            $run->status = $target;
            $run->save();

            return $run->refresh();
        });
    }

    private function guardTransition(PayrollRun $run, PayrollRunStatus $target): void
    {
        if (! in_array($target, $run->status->allowedNext(), true)) {
            throw new InvalidStateTransitionException(
                "Payroll run {$run->period} cannot move from {$run->status->value} to {$target->value}."
            );
        }
    }

    /**
     * Hourly pay resolves the role rate per attendance date (HR-04), falling
     * back to the employee's personal rate. An hourly employee with payable
     * hours but no resolvable rate is a refusal, not a silent zero.
     */
    private function attendancePay(Employee $employee, Carbon $start, Carbon $end): float
    {
        $attendances = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->get()
            ->filter(fn (Attendance $a) => $a->status->isPayable() && (float) $a->hours_worked > 0);

        $pay = 0.0;

        foreach ($attendances as $attendance) {
            $rate = $employee->labor_role !== null
                ? LaborRoleRate::rateFor($employee->labor_role, $attendance->work_date)
                : null;

            $rate ??= $employee->hourly_rate !== null ? (float) $employee->hourly_rate : null;

            if ($rate === null) {
                throw new InvalidArgumentException(
                    "Employee {$employee->id} has payable hours but no labor role rate and no personal rate."
                );
            }

            $pay = round($pay + ((float) $attendance->hours_worked * $rate), 4);
        }

        return $pay;
    }

    /**
     * HR-08 / phase-08 §8.4 PayrollRun.Posted, per unit:
     *
     *   DR 57      Wage Expense               (gross pay)
     *   CR 1211xx  the chosen treasury        (net pay)
     *   CR 225     Payroll deductions payable (withheld)
     */
    private function postJournal(PayrollRun $run, ?string $cashAccountId): void
    {
        $treasuryCode = TreasuryAccounts::codeForId($cashAccountId);
        $payslips = $run->payslips()->get();

        $lines = [];

        foreach ($payslips->groupBy('operating_unit_id') as $unitId => $unitSlips) {
            $expensePortion = round((float) $unitSlips->sum('gross_pay'), 4);
            $net = round((float) $unitSlips->sum('net_pay'), 4);
            $deductions = round((float) $unitSlips->sum(fn (Payslip $p) => $p->totalDeductions()), 4);

            if ($expensePortion > 0) {
                $lines[] = [
                    'account_code' => '57', // Wage Expense
                    'debit' => $expensePortion,
                    'operating_unit_id' => $unitId,
                ];
            }

            if ($net > 0) {
                $lines[] = [
                    'account_code' => $treasuryCode,
                    'credit' => $net,
                    'operating_unit_id' => $unitId,
                    'memo' => 'net pay disbursed',
                ];
            }

            if ($deductions > 0) {
                $lines[] = [
                    'account_code' => self::DEDUCTIONS_PAYABLE,
                    'credit' => $deductions,
                    'operating_unit_id' => $unitId,
                ];
            }
        }

        $entry = $this->accountingService->postJournal(
            "Payroll {$run->period} posted",
            $lines,
            'PayrollRun',
            $run->id,
            $run->company_id,
        );

        $run->payslips()->update(['journal_entry_id' => $entry->id]);
    }
}
