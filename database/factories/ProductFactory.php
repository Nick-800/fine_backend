<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $types = ['Mattress', 'Cushion', 'Sofa Section', 'Pillow', 'Bolster', 'Bed Base'];
        $sizes = ['Single', 'Double', 'Queen', 'King', 'Compact'];
        $name = fake()->randomElement($sizes).' '.fake()->randomElement($types);

        return [
            'name' => $name,
            'sku' => 'PROD-'.strtoupper(substr(fake()->uuid(), 0, 8)),
            'description' => fake()->sentence(),
            'markup_factor' => fake()->randomFloat(3, 1.15, 1.50),
        ];
    }
}
