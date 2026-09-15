<?php

declare(strict_types=1);

namespace Database\Seeders\Dummy;

use App\Enums\AttendanceStatus;
use App\Enums\LeaveRequestStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Dummy leave requests in every realistic status and attendance rows for
 * the last 30 days so the HR dashboard has shape.
 */
class HrSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::all();
        if ($employees->isEmpty()) {
            return;
        }

        $hrManager = User::where('email', 'hr@erp.com')->first();

        // Leave requests
        $statuses = [
            LeaveRequestStatus::Pending,
            LeaveRequestStatus::Approved,
            LeaveRequestStatus::Rejected,
        ];

        foreach ($employees->take(8) as $index => $employee) {
            $start = now()->addDays(fake()->numberBetween(-30, 30));
            LeaveRequest::create([
                'id' => (string) Str::uuid(),
                'employee_id' => $employee->id,
                'operating_unit_id' => $employee->operating_unit_id,
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addDays(fake()->numberBetween(1, 5))->toDateString(),
                'leave_type' => fake()->randomElement(['annual', 'sick', 'unpaid', 'other']),
                'reason' => fake()->optional(0.6)->sentence(),
                'status' => $statuses[$index % count($statuses)],
                'decided_by_user_id' => $index % count($statuses) !== 0 ? $hrManager?->id : null,
                'decided_at' => $index % count($statuses) !== 0 ? now() : null,
                'decision_notes' => $index % count($statuses) === 2 ? 'Insufficient context — please resubmit with documentation.' : null,
            ]);
        }

        // Attendance rows for the last 30 days, sampled across employees
        foreach ($employees as $employee) {
            for ($d = 0; $d < 30; $d++) {
                $date = now()->subDays($d)->toDateString();
                if (Attendance::where('employee_id', $employee->id)->whereDate('work_date', $date)->exists()) {
                    continue;
                }

                $status = fake()->randomElement([
                    AttendanceStatus::Present,
                    AttendanceStatus::Present,
                    AttendanceStatus::Present,
                    AttendanceStatus::Present,
                    AttendanceStatus::HalfDay,
                    AttendanceStatus::Absent,
                    AttendanceStatus::Leave,
                ]);

                Attendance::create([
                    'id' => (string) Str::uuid(),
                    'employee_id' => $employee->id,
                    'operating_unit_id' => $employee->operating_unit_id,
                    'work_date' => $date,
                    'status' => $status,
                    'hours_worked' => $status->isPayable() ? fake()->randomFloat(2, 4, 9) : 0,
                    'notes' => null,
                ]);
            }
        }

        // Suppress unused variable
        unset($hrManager);
    }
}
