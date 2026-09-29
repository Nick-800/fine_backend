<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OperatingUnit;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    public function definition(): array
    {
        return [
            'operating_unit_id' => OperatingUnit::factory(),
            'quotation_number' => 'Q-'.now()->format('Y').'-'.fake()->unique()->numerify('#####'),
            'valid_until' => today()->addDays(14),
            'status' => Quotation::STATUS_OPEN,
            'total_amount' => 0,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['valid_until' => today()->subDay()]);
    }
}
