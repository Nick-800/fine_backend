<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StockLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLot>
 */
class StockLotFactory extends Factory
{
    protected $model = StockLot::class;

    public function definition(): array
    {
        return [
            'lot_number' => 'LOT-'.fake()->numberBetween(10000, 99999),
            'quantity' => fake()->randomFloat(4, 50, 1000),
            'unit_cost' => fake()->randomFloat(4, 2, 50),
            'status' => 'available',
        ];
    }
}
