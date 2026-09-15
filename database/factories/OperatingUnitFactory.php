<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OperatingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperatingUnit>
 */
class OperatingUnitFactory extends Factory
{
    protected $model = OperatingUnit::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Unit',
            'unit_type' => 'manufactory',
            'currency' => 'LYD',
            'status' => 'active',
        ];
    }
}
