<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaterialRequest;
use App\Models\OperatingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialRequest>
 */
class MaterialRequestFactory extends Factory
{
    protected $model = MaterialRequest::class;

    public function definition(): array
    {
        return [
            'fulfilling_module' => fake()->randomElement(['foam', 'cutter', 'procurement']),
            'quantity' => fake()->randomFloat(4, 10, 500),
            'status' => MaterialRequest::STATUS_PENDING,
            'operating_unit_id' => OperatingUnit::factory(),
        ];
    }

    public function fulfilled(): static
    {
        return $this->state(fn () => [
            'status' => MaterialRequest::STATUS_FULFILLED,
            'fulfilled_at' => now(),
        ]);
    }
}
