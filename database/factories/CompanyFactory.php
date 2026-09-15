<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' '.Str::random(4),
            'default_currency' => 'LYD',
            'overhead_absorption_enabled' => false,
            'transfer_pricing_mode' => 'at_cost',
            'timezone' => 'Africa/Tripoli',
        ];
    }
}
