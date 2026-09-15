<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Entity;
use App\Models\ExternalEmployer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalEmployer>
 */
class ExternalEmployerFactory extends Factory
{
    protected $model = ExternalEmployer::class;

    public function definition(): array
    {
        return [
            'entity_id' => Entity::factory()->organization(),
            'contract_reference' => 'AGENCY-'.fake()->year().'-'.fake()->numberBetween(100, 999),
            'billing_rate_multiplier' => fake()->randomFloat(2, 1.05, 1.30),
        ];
    }
}
