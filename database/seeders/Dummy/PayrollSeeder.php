<?php

declare(strict_types=1);

namespace Database\Seeders\Dummy;

use App\Enums\PayrollRunStatus;
use App\Models\Employee;
use App\Models\LaborRoleRate;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Two payroll runs for the most recent closed month and the month before:
 * each goes through Draft → Calculated → Approved so payslips and totals are
 * populated. Uses PayrollService so the ledger postings (HR-08) actually run.
 */
class PayrollSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(PayrollService::class);
        $hrManager = User::where('email', 'hr@erp.com')->first();

        if (Employee::count() === 0) {
            return;
        }

        $periods = [
            now()->subMonths(2)->format('Y-m'),
            now()->subMonths(1)->format('Y-m'),
        ];

        foreach ($periods as $period) {
            $existing = PayrollRun::where('period', $period)->first();
            if ($existing !== null) {
                continue;
            }

            try {
                $run = $service->open($period, $hrManager?->id);
                $service->calculate($run);
                $service->transition($run->fresh(), PayrollRunStatus::PendingApproval, $hrManager?->id);
                $service->transition($run->fresh(), PayrollRunStatus::Approved, $hrManager?->id);
            } catch (\Throwable $e) {
                // Period may already have a run, or guards may refuse;
                // leave the run in whatever state was reached.
            }
        }

        // Make sure LaborRoleRate has at least one entry for the rates logic.
        $effectiveFrom = Carbon::parse('2026-01-01')->toDateString();
        $existing = LaborRoleRate::where('role', 'assembler')
            ->whereDate('effective_from', $effectiveFrom)
            ->first();

        if ($existing === null) {
            LaborRoleRate::create([
                'id' => (string) Str::uuid(),
                'role' => 'assembler',
                'effective_from' => $effectiveFrom,
                'hourly_rate' => 12,
            ]);
        }

        // Suppress unused variable
        unset($hrManager);
    }
}
