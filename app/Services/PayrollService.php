<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PayrollRunStatus;
use App\Enums\PayType;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LaborLog;
use App\Models\LaborRoleRate;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 09 §9.4–9.7. A run opens a period, aggregates attendance and labor
 * logs into payslips (§9.6), passes review and approval gates (HR-06), and
 * posts to the ledger on the final transition (HR-08).
 *
 * The posting splits gross pay: hours logged against production orders were
 * already accrued into 2200 Wages Payable when the order finished, so payroll
 * settles that liability; everything else (base salary, attendance pay) is
 * fresh expense on 5700. Logs on orders that have not finished yet still
 * settle 2200 — the account dips until the order closes and accrues it back,
 * which self-corrects and beats double-expensing the same hours.
 */
final class PayrollService
{
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
     * §9.6: gross = base salary + payable attendance hours × role rate
     * + labor-log hours × their snapshotted rates. Recalculating while still
     * in Calculated wipes and regenerates the payslips.
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
                    ? round((float) ($employee->monthly_salary ?? 0), 4)
                    : 0.0;

                $attendancePay = $employee->pay_type === PayType::Hourly
                    ? $this->attendancePay($employee, $start, $end)
                    : 0.0;

                $laborLogPay = round((float) LaborLog::where('employee_id', $employee->id)
                    ->whereBetween('logged_at', [$start, $end->copy()->endOfDay()])
                    ->get()
                    ->sum(fn (LaborLog $log) => $log->cost()), 4);

                $gross = round($basePay + $attendancePay + $laborLogPay, 4);

                if ($gross <= 0.0) {
                    continue;
                }

                Payslip::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'operating_unit_id' => $employee->operating_unit_id,
                    'base_pay' => $basePay,
                    'attendance_pay' => $attendancePay,
                    'labor_log_pay' => $laborLogPay,
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

    public function transition(PayrollRun $run, PayrollRunStatus $target, ?string $userId = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $target, $userId) {
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
                $this->postJournal($run);
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
     *   DR 2200 Wages Payable   (labor-log portion, accrued at order close)
     *   DR 5700 Wage Expense    (base + attendance portion)
     *   CR 1200 Cash and Bank   (net pay)
     *   CR 2210 Deductions      (withheld)
     */
    private function postJournal(PayrollRun $run): void
    {
        $payslips = $run->payslips()->get();

        $lines = [];

        foreach ($payslips->groupBy('operating_unit_id') as $unitId => $unitSlips) {
            $laborPortion = round((float) $unitSlips->sum('labor_log_pay'), 4);
            $expensePortion = round((float) $unitSlips->sum('gross_pay') - $laborPortion, 4);
            $net = round((float) $unitSlips->sum('net_pay'), 4);
            $deductions = round((float) $unitSlips->sum(fn (Payslip $p) => $p->totalDeductions()), 4);

            if ($laborPortion > 0) {
                $lines[] = [
                    'account_code' => '22', // Wages Payable — accrued at production close
                    'debit' => $laborPortion,
                    'operating_unit_id' => $unitId,
                    'memo' => 'production labor settled',
                ];
            }

            if ($expensePortion > 0) {
                $lines[] = [
                    'account_code' => '57', // Wage Expense
                    'debit' => $expensePortion,
                    'operating_unit_id' => $unitId,
                ];
            }

            if ($net > 0) {
                $lines[] = [
                    'account_code' => '12', // Cash and Bank
                    'credit' => $net,
                    'operating_unit_id' => $unitId,
                    'memo' => 'net pay disbursed',
                ];
            }

            if ($deductions > 0) {
                $lines[] = [
                    'account_code' => '221', // Payroll Deductions Payable
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
