<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProductionBatchStatus;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\Warehouse;
use App\Services\ConsumptionReportService;
use App\Services\ProductionBatchService;
use Illuminate\Database\Seeder;

/**
 * A complete foam run, seeded from the real production report for operation 191.
 *
 * Walks the batch through its whole lifecycle rather than writing rows directly,
 * so the seeded data is guaranteed to be reachable through the same guards and
 * services the application enforces at runtime.
 */
class FoamProductionSeeder extends Seeder
{
    public function run(): void
    {
        $batchService = app(ProductionBatchService::class);
        $consumptionService = app(ConsumptionReportService::class);

        $foamUnit = OperatingUnit::where('unit_type', 'foam_manufactory')->first()
            ?? OperatingUnit::where('name', 'like', '%Foam%')->first()
            ?? OperatingUnit::first();

        $warehouse = Warehouse::where('operating_unit_id', $foamUnit->id)->first();
        $blockItem = InventoryItem::where('sku', 'BLOCK-WHITE-1214')->first();
        $scrapItem = InventoryItem::where('sku', 'SCRAP-FILL')->first();

        if ($warehouse === null || $blockItem === null || $scrapItem === null) {
            return;
        }

        $batch = ProductionBatch::create([
            'operating_unit_id' => $foamUnit->id,
            'operation_number' => 191,
            'bun_width_m' => 2.4,
            'formula_params' => [
                'density_band' => '12-14',
                'cure_time_minutes' => 15,
                'conveyor_speed' => 5,
                'chemical_formula' => 'standard-white',
            ],
            'status' => ProductionBatchStatus::Planned->value,
        ]);

        $batchService->transition($batch, ProductionBatchStatus::Configured);
        $batchService->transition($batch->fresh(), ProductionBatchStatus::Running);

        // Chemical draw, in the proportions on the report.
        $consumptionService->record($batch->fresh(), [
            ['chemical_inventory_item_id' => InventoryItem::where('sku', 'CHEM-POLYOL-15')->value('id'), 'quantity_consumed' => 899],
            ['chemical_inventory_item_id' => InventoryItem::where('sku', 'CHEM-POLYOL-45')->value('id'), 'quantity_consumed' => 899],
            ['chemical_inventory_item_id' => InventoryItem::where('sku', 'CHEM-TDI-SABEC')->value('id'), 'quantity_consumed' => 1129],
            ['chemical_inventory_item_id' => InventoryItem::where('sku', 'CHEM-MC')->value('id'), 'quantity_consumed' => 270],
        ]);

        foreach ([ProductionBatchStatus::Consumed, ProductionBatchStatus::Curing, ProductionBatchStatus::ReadyForGrading] as $status) {
            $batchService->transition($batch->fresh(), $status);
        }

        $block = fn (int $count, float $length, float $height) => [
            'kind' => 'block',
            'count' => $count,
            'length_m' => $length,
            'height_m' => $height,
            'pressure' => 35,
            'inventory_item_id' => $blockItem->id,
            'warehouse_id' => $warehouse->id,
            'grade' => 'standard',
            'color' => 'white',
        ];

        $scrap = fn (float $length, float $height) => [
            'kind' => 'scrap',
            'count' => 1,
            'length_m' => $length,
            'height_m' => $height,
            'inventory_item_id' => $scrapItem->id,
            'warehouse_id' => $warehouse->id,
        ];

        // The nine rows of the paper report: 34 blocks plus فاصل and بداية.
        // Block volumes plus scrap sum to the printed 135.657 m³.
        $batchService->registerBlocks($batch->fresh(), [
            $block(25, 2.0, 0.8),
            $block(3, 1.99, 1.0),
            $block(2, 1.38, 0.44),
            $block(1, 2.3, 0.78),
            $block(1, 2.5, 0.59),
            $block(1, 1.95, 0.69),
            $block(1, 2.3, 0.75),
            $scrap(2.0, 1.0),   // فاصل
            $scrap(2.0, 0.5),   // بداية
        ]);

        // Closing apportions the run's material cost across the blocks by volume.
        $batchService->transition($batch->fresh(), ProductionBatchStatus::Graded);
        $batchService->transition($batch->fresh(), ProductionBatchStatus::Closed);
    }
}
