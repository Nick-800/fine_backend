<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SaleFulfillmentStatus;
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
            'order_number' => 'S-'.now()->format('Y').'-'.fake()->unique()->numerify('#####'),
            'buyer_type' => 'client',
            'channel' => 'pos',
            'status' => SalesOrderStatus::Open,
            'fulfillment_status' => SaleFulfillmentStatus::Delivered,
            'payment_method' => 'cash',
        ];
    }

    public function internal(): static
    {
        return $this->state(fn () => [
            'buyer_type' => 'internal_unit',
            'client_id' => null,
            'payment_method' => null,
            'status' => SalesOrderStatus::Completed,
        ]);
    }

    public function receivable(): static
    {
        return $this->state(fn () => ['payment_method' => 'receivable']);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn () => ['status' => SalesOrderStatus::PendingApproval, 'fulfillment_status' => null]);
    }
}
