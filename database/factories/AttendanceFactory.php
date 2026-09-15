<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\OperatingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'operating_unit_id' => OperatingUnit::factory(),
            'work_date' => fake()->dateTimeBetween('-60 days', 'now')->format('Y-m-d'),
            'status' => fake()->randomElement(AttendanceStatus::cases()),
            'hours_worked' => fake()->randomFloat(2, 0, 9),
            'notes' => fake()->optional(0.2)->sentence(),
        ];
    }

    public function present(): static
    {
        return $this->state(fn () => [
            'status' => AttendanceStatus::Present,
            'hours_worked' => fake()->randomFloat(2, 7, 9),
        ]);
    }
}
