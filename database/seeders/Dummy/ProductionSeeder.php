<?php

declare(strict_types=1);

namespace Database\Seeders\Dummy;

use App\Enums\ProductionBatchStatus;
use App\Models\CutterWorkOrder;
use App\Models\CutterWorkOrderLine;
use App\Models\InternalRestockRequest;
use App\Models\InternalRestockRequestLine;
use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\OperatingUnit;
use App\Models\ProductionBatch;
use App\Models\StockLot;
use App\Models\Warehouse;
use App\Services\ProductionBatchService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Adds extra foam batches (some closed, some still planned), cutter work
 * orders in various stages, furniture production orders in the requested
 * state, plus material and internal-restock requests.
 *
 * Lifecycle-heavy paths (full foam run, block selection, full assembly) are
 * expensive to drive purely from a seeder because every transition has
 * guards. This seeder lands rows at the realistic mid-flow states the rest
 * of the system can pick up from, without trying to fake every precondition.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $foamUnit = OperatingUnit::where('name', 'مصنع الإسفنج')->first();
        $cutterUnit = OperatingUnit::where('name', 'قسم القص')->first();
        $furnitureUnit = OperatingUnit::where('name', 'قسم الأثاث')->first();
        $showroom = OperatingUnit::where('name', 'صالة العرض')->first();

        if ($foamUnit === null || $cutterUnit === null || $furnitureUnit === null || $showroom === null) {
            return;
        }

        $this->seedExtraFoamBatches($foamUnit);
        $this->seedCutterWorkOrders($cutterUnit);
        $this->seedMaterialRequests($furnitureUnit, $foamUnit);
        $this->seedInternalRestockRequests($cutterUnit, $showroom);
    }

    private function seedExtraFoamBatches(OperatingUnit $foamUnit): void
    {
        $batchService = app(ProductionBatchService::class);
        $warehouse = Warehouse::where('operating_unit_id', $foamUnit->id)->first();
        $blockItem = InventoryItem::where('sku', 'BLOCK-WHITE-1214')->first();
        $scrapItem = InventoryItem::where('sku', 'SCRAP-FILL')->first();

        if ($warehouse === null || $blockItem === null || $scrapItem === null) {
            return;
        }

        // 1. A fully closed run (realistic cost apportionment + inventory output)
        $closedBatch = ProductionBatch::firstOrCreate(
            ['operation_number' => 192],
            [
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $foamUnit->id,
                'bun_width_m' => 2.4,
                'formula_params' => ['density_band' => '12-14', 'cure_time_minutes' => 15, 'conveyor_speed' => 5, 'chemical_formula' => 'standard-white'],
                'status' => ProductionBatchStatus::Planned->value,
            ],
        );

        try {
            $batchService->transition($closedBatch, ProductionBatchStatus::Configured);
            $batchService->transition($closedBatch->fresh(), ProductionBatchStatus::Running);
            foreach ([ProductionBatchStatus::Consumed, ProductionBatchStatus::Curing, ProductionBatchStatus::ReadyForGrading] as $s) {
                $batchService->transition($closedBatch->fresh(), $s);
            }
            $batchService->registerBlocks($closedBatch->fresh(), [
                [
                    'kind' => 'block', 'count' => 12, 'length_m' => 2.0, 'height_m' => 0.8, 'pressure' => 35,
                    'inventory_item_id' => $blockItem->id, 'warehouse_id' => $warehouse->id, 'grade' => 'standard', 'color' => 'white',
                ],
                ['kind' => 'scrap', 'count' => 1, 'length_m' => 2.0, 'height_m' => 0.4, 'inventory_item_id' => $scrapItem->id, 'warehouse_id' => $warehouse->id],
            ]);
            $batchService->transition($closedBatch->fresh(), ProductionBatchStatus::Graded);
            $batchService->transition($closedBatch->fresh(), ProductionBatchStatus::Closed);
        } catch (\Throwable $e) {
            // Service-level guards may block the full path (or it already ran
            // on a previous seed); leave the batch in whichever state it
            // reached for manual recovery.
        }

        // 2. A planned batch waiting to be configured
        ProductionBatch::firstOrCreate(
            ['operation_number' => 193],
            [
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $foamUnit->id,
                'bun_width_m' => 2.4,
                'formula_params' => ['density_band' => '18-22', 'cure_time_minutes' => 18, 'conveyor_speed' => 4, 'chemical_formula' => 'firm-yellow'],
                'status' => ProductionBatchStatus::Planned->value,
            ],
        );

        // 3. A running batch (with chemical draw reported)
        $running = ProductionBatch::firstOrCreate(
            ['operation_number' => 194],
            [
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $foamUnit->id,
                'bun_width_m' => 2.4,
                'formula_params' => ['density_band' => '25-30', 'cure_time_minutes' => 22, 'conveyor_speed' => 4, 'chemical_formula' => 'firm-orange'],
                'status' => ProductionBatchStatus::Running->value,
            ],
        );

        try {
            $batchService->transition($running, ProductionBatchStatus::Configured);
            $batchService->transition($running->fresh(), ProductionBatchStatus::Consumed);
        } catch (\Throwable $e) {
            // ignore — partial state still useful for inspection
        }
    }

    private function seedCutterWorkOrders(OperatingUnit $cutterUnit): void
    {
        $availableBlock = StockLot::whereHas('inventoryItem', fn ($q) => $q->where('item_type', 'foam_block'))
            ->where('status', 'available')
            ->where('quantity', 1)
            ->first();

        for ($i = 1; $i <= 4; $i++) {
            $order = CutterWorkOrder::firstOrCreate(
                ['order_number' => 'CWO-DUMMY-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)],
                [
                    'id' => (string) Str::uuid(),
                    'operating_unit_id' => $cutterUnit->id,
                    'status' => $i <= 2 ? 'requested' : ($i === 3 ? 'confirmed' : 'in_production'),
                    'notes' => 'Dummy cutter work order.',
                ],
            );

            if (! $order->lines()->exists()) {
                CutterWorkOrderLine::create([
                    'id' => (string) Str::uuid(),
                    'cutter_work_order_id' => $order->id,
                    'requested_spec' => fake()->randomElement(['100x60x10', '120x60x12', '80x80x8']),
                    'quantity' => fake()->numberBetween(1, 5),
                    'template_length_m' => 1.0,
                    'template_width_m' => 0.6,
                    'template_height_m' => 0.1,
                    'template_volume_m3' => 0.06,
                ]);
            }
        }
    }

    private function seedMaterialRequests(OperatingUnit $furnitureUnit, OperatingUnit $foamUnit): void
    {
        $sliceItem = InventoryItem::where('sku', 'SLICE-STD')->first();
        $item = $sliceItem ?? InventoryItem::first();

        for ($i = 1; $i <= 6; $i++) {
            $status = $i <= 2 ? MaterialRequest::STATUS_PENDING : ($i <= 4 ? MaterialRequest::STATUS_IN_PROGRESS : MaterialRequest::STATUS_FULFILLED);
            MaterialRequest::create([
                'id' => (string) Str::uuid(),
                'fulfilling_module' => MaterialRequest::MODULE_FOAM,
                'inventory_item_id' => $item?->id,
                'quantity' => 4 * $i,
                'status' => $status,
                'operating_unit_id' => $foamUnit->id,
                'fulfilled_at' => $status === MaterialRequest::STATUS_FULFILLED ? now() : null,
            ]);
        }
    }

    private function seedInternalRestockRequests(OperatingUnit $cutterUnit, OperatingUnit $showroom): void
    {
        $cutPieceItem = InventoryItem::whereIn('sku', ['CUT-100-60-10', 'CUT-120-60-12'])->first();

        for ($i = 1; $i <= 3; $i++) {
            $request = InternalRestockRequest::firstOrCreate(
                ['request_number' => 'IRR-DUMMY-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)],
                [
                    'id' => (string) Str::uuid(),
                    'requesting_unit_id' => $showroom->id,
                    'source_unit_id' => $cutterUnit->id,
                    'status' => $i === 1 ? 'pending_approval' : 'approved',
                    'notes' => 'Dummy internal restock request.',
                ],
            );

            if (! $request->lines()->exists() && $cutPieceItem !== null) {
                InternalRestockRequestLine::create([
                    'id' => (string) Str::uuid(),
                    'internal_restock_request_id' => $request->id,
                    'inventory_item_id' => $cutPieceItem->id,
                    'quantity' => fake()->numberBetween(5, 20),
                ]);
            }
        }
    }
}
