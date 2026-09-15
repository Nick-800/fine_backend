<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DepreciationMethod;
use App\Enums\FixedAssetStatus;
use App\Models\Company;
use App\Models\FixedAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixedAsset>
 */
class FixedAssetFactory extends Factory
{
    protected $model = FixedAsset::class;

    public function definition(): array
    {
        $cost = fake()->randomFloat(4, 5_000, 500_000);

        return [
            'company_id' => Company::factory(),
            'name' => fake()->randomElement(['Foam Line Mixer', 'Cutter CNC', 'Delivery Truck', 'Forklift', 'Office Generator']).' '.fake()->numberBetween(1, 9),
            'asset_code' => 'FA-'.fake()->unique()->numberBetween(10000, 99999),
            'acquisition_cost' => $cost,
            'acquisition_date' => fake()->dateTimeBetween('-5 years', '-2 months')->format('Y-m-d'),
            'depreciation_method' => fake()->randomElement(DepreciationMethod::cases()),
            'useful_life_years' => fake()->randomElement([3, 5, 7, 10]),
            'salvage_value' => $cost * 0.05,
            'status' => FixedAssetStatus::Active,
        ];
    }
}
