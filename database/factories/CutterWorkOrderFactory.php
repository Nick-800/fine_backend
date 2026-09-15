<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CutterWorkOrderStatus;
use App\Models\CutterWorkOrder;
use App\Models\OperatingUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CutterWorkOrder>
 */
class CutterWorkOrderFactory extends Factory
{
    protected $model = CutterWorkOrder::class;

    public function definition(): array
    {
        return [
            'operating_unit_id' => OperatingUnit::factory(),
            'order_number' => 'CWO-'.fake()->unique()->numberBetween(100000, 999999),
            'status' => CutterWorkOrderStatus::Requested,
        ];
    }
}
