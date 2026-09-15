<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PayrollRunStatus;
use App\Models\Company;
use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        $period = fake()->dateTimeBetween('-6 months', '-1 month')->format('Y-m');

        return [
            'company_id' => Company::factory(),
            'period' => $period,
            'status' => PayrollRunStatus::Draft,
            'total_gross' => 0,
            'total_deductions' => 0,
            'total_net' => 0,
        ];
    }
}
