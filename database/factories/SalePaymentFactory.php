<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SalePayment;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalePayment>
 */
class SalePaymentFactory extends Factory
{
    protected $model = SalePayment::class;

    public function definition(): array
    {
        return [
            'sales_order_id' => SalesOrder::factory(),
            'operating_unit_id' => fn (array $attributes) => SalesOrder::withoutGlobalScopes()->find($attributes['sales_order_id'])?->operating_unit_id,
            'amount' => fake()->randomFloat(2, 10, 1000),
            'method' => 'cash',
            'received_at' => now(),
        ];
    }
}
