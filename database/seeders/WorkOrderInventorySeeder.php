<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InventoryMovement;
use App\Models\OperatingUnit;
use App\Models\WorkOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class WorkOrderInventorySeeder extends Seeder
{
    public function run(): void
    {
        $unit = OperatingUnit::first();

        if (! $unit) {
            return;
        }

        // 1. Seed Work Orders
        $wo1 = WorkOrder::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'product_sku' => 'FOAM-BLOCK-D28-WHITE',
            'quantity' => 150.0,
            'status' => 'completed',
        ]);

        $wo2 = WorkOrder::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'product_sku' => 'MATTRESS-KING-LUX',
            'quantity' => 50.0,
            'status' => 'open',
        ]);

        // 2. Seed Inventory Movements
        InventoryMovement::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'sku' => 'RAW-TDI-CHEMICAL-USD',
            'quantity_delta' => 1000.0,
            'reason' => 'goods_receipt',
            'reference_id' => $wo1->id,
        ]);

        InventoryMovement::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unit->id,
            'sku' => 'FOAM-BLOCK-D28-WHITE',
            'quantity_delta' => 150.0,
            'reason' => 'production_output',
            'reference_id' => $wo1->id,
        ]);
    }
}
