<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' '.fake()->randomElement(['Trading', 'Industries', 'Imports', 'Co.']),
            'contact' => fake()->safeEmail(),
            'default_currency' => fake()->randomElement(['USD', 'EUR', 'LYD']),
            'address' => fake()->streetAddress().', '.fake()->city(),
        ];
    }

    public function usd(): static
    {
        return $this->state(fn () => ['default_currency' => 'USD']);
    }
}
