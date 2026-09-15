<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bom>
 */
class BomFactory extends Factory
{
    protected $model = Bom::class;

    public function definition(): array
    {
        return [
            'version' => 1,
            'is_active' => true,
            'notes' => fake()->optional(0.4)->sentence(),
        ];
    }
}
