<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveType;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OperatingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-60 days', '+30 days');

        return [
            'employee_id' => Employee::factory(),
            'operating_unit_id' => OperatingUnit::factory(),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => (clone $start)->modify('+'.fake()->numberBetween(1, 10).' days')->format('Y-m-d'),
            'leave_type' => fake()->randomElement(LeaveType::cases()),
            'reason' => fake()->optional(0.7)->sentence(),
            'status' => LeaveRequestStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => LeaveRequestStatus::Approved,
            'decided_at' => now(),
        ]);
    }
}
