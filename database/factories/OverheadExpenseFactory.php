<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OverheadCategory;
use App\Enums\OverheadExpenseStatus;
use App\Enums\OverheadPaymentSource;
use App\Models\Company;
use App\Models\OverheadExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OverheadExpense>
 */
class OverheadExpenseFactory extends Factory
{
    protected $model = OverheadExpense::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'category' => fake()->randomElement(OverheadCategory::cases()),
            'description' => fake()->sentence(6),
            'amount' => fake()->randomFloat(4, 200, 8000),
            'currency' => 'LYD',
            'expense_date' => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'payment_source' => OverheadPaymentSource::Cash,
            'status' => OverheadExpenseStatus::Recorded,
        ];
    }
}
