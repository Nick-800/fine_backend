<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductionOrderStatus;
use App\Models\OperatingUnit;
use App\Models\ProductionOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionOrder>
 */
class ProductionOrderFactory extends Factory
{
    protected $model = ProductionOrder::class;

    public function definition(): array
    {
        return [
            'operating_unit_id' => OperatingUnit::factory(),
            'order_number' => 'PO-'.fake()->unique()->numberBetween(100000, 999999),
            'quantity' => fake()->numberBetween(1, 50),
            'status' => ProductionOrderStatus::Requested,
        ];
    }
}
