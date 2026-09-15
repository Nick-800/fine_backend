<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        $types = ['raw_material', 'foam_block', 'slice', 'byproduct_fill', 'barrel', 'pallet', 'cut_piece', 'finished_good'];
        $type = fake()->randomElement($types);

        return [
            'name' => fake()->words(3, true).' '.fake()->randomElement(['Foam', 'Block', 'Slice', 'Component']),
            'sku' => strtoupper(Str::random(3)).'-'.fake()->numberBetween(1000, 9999),
            'item_type' => $type,
            'unit_of_measure' => fake()->randomElement(['liter', 'kg', 'm3', 'each']),
        ];
    }

    public function ofType(string $type): static
    {
        return $this->state(fn () => ['item_type' => $type]);
    }

    public function barrel(string $emptySku = 'BARREL-200-EMPTY', int $capacity = 200): static
    {
        return $this->state(fn () => [
            'item_type' => 'raw_material',
            'unit_of_measure' => 'liter',
            'primary_uom' => 'barrel',
            'secondary_uom' => 'liter',
            'container_capacity' => $capacity,
        ]);
    }
}
