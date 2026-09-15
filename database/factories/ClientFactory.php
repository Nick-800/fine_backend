<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\Entity;
use App\Models\OperatingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'entity_id' => Entity::factory(),
            'operating_unit_id' => OperatingUnit::factory(),
            'credit_limit' => fake()->randomFloat(4, 25_000, 500_000),
            'current_balance' => 0,
            'payment_terms_days' => fake()->randomElement([30, 45, 60, 90]),
            'status' => ClientStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => ClientStatus::Suspended]);
    }

    public function withBalance(float $amount): static
    {
        return $this->state(fn () => ['current_balance' => $amount]);
    }
}
