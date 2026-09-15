<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SalesOrderStatus;
use App\Models\OperatingUnit;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    public function definition(): array
    {
        return [
            'operating_unit_id' => OperatingUnit::factory(),
            'order_number' => 'SO-'.fake()->unique()->numberBetween(100000, 999999),
            'buyer_type' => 'walk_in',
            'channel' => 'standard',
            'status' => SalesOrderStatus::Draft,
            'payment_method' => 'cash',
        ];
    }

    public function walkIn(): static
    {
        return $this->state(fn () => ['buyer_type' => 'walk_in']);
    }

    public function internal(): static
    {
        return $this->state(fn () => ['buyer_type' => 'internal_unit', 'client_id' => null]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => SalesOrderStatus::Confirmed]);
    }
}
