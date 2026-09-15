<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EntityType;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Entity>
 */
class EntityFactory extends Factory
{
    protected $model = Entity::class;

    public function definition(): array
    {
        $isOrg = fake()->boolean(60);

        return [
            'name' => $isOrg ? fake()->company() : fake()->name(),
            'entity_type' => $isOrg ? EntityType::Organization : EntityType::Individual,
            'tax_number' => 'TAX-'.strtoupper(Str::random(8)),
            'is_active' => true,
        ];
    }

    public function organization(): static
    {
        return $this->state(fn () => [
            'entity_type' => EntityType::Organization,
            'name' => fake()->company(),
        ]);
    }

    public function individual(): static
    {
        return $this->state(fn () => [
            'entity_type' => EntityType::Individual,
            'name' => fake()->name(),
        ]);
    }
}
