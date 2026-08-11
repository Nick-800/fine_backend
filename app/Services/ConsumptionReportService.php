<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InsufficientTankStockException;
use App\Models\ConsumptionLine;
use App\Models\ConsumptionReport;
use App\Models\InventoryMovement;
use App\Models\ProductionBatch;
use App\Models\TankStock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ConsumptionReportService
{
    /**
     * Record what a run actually consumed and draw it from the tanks.
     *
     * Everything happens in one transaction: either the whole report lands with
     * tanks decremented and movements written, or nothing does. A half-applied
     * consumption would leave tank levels lying about what is physically there.
     *
     * @param  array<int, array{chemical_inventory_item_id: string, quantity_consumed: float|int}>  $lines
     */
    public function record(ProductionBatch $batch, array $lines): ConsumptionReport
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A consumption report needs at least one chemical line.');
        }

        return DB::transaction(function () use ($batch, $lines): ConsumptionReport {
            $unitId = $batch->operating_unit_id;

            // Lock every tank up front, then check sufficiency across the whole
            // report before drawing anything (FOAM-02). Checking per-line as we go
            // could drain the first chemicals and only then discover the last one
            // is short, leaving the run half-consumed.
            $tanks = [];
            $required = [];

            foreach ($lines as $line) {
                $itemId = $line['chemical_inventory_item_id'];
                $quantity = (float) $line['quantity_consumed'];

                if ($quantity < 0) {
                    throw new InvalidArgumentException('Consumed quantity cannot be negative.');
                }

                // A zero line is meaningful data — the production report lists
                // optional inputs that were not used on this run.
                $required[$itemId] = ($required[$itemId] ?? 0.0) + $quantity;
            }

            foreach (array_keys($required) as $itemId) {
                $tank = TankStock::where('chemical_inventory_item_id', $itemId)
                    ->where('operating_unit_id', $unitId)
                    ->lockForUpdate()
                    ->first();

                $tanks[$itemId] = $tank;
            }

            $shortfalls = [];

            foreach ($required as $itemId => $quantity) {
                if ($quantity === 0.0) {
                    continue;
                }

                $onHand = $tanks[$itemId] !== null ? (float) $tanks[$itemId]->quantity_on_hand : 0.0;

                if ($onHand < $quantity) {
                    $sku = $tanks[$itemId]?->chemicalItem?->sku ?? $itemId;
                    $shortfalls[] = "{$sku}: need {$quantity}, have {$onHand}";
                }
            }

            if ($shortfalls !== []) {
                throw new InsufficientTankStockException(
                    'Tank levels are insufficient for this run — '.implode('; ', $shortfalls).'.'
                );
            }

            $report = ConsumptionReport::create([
                'production_batch_id' => $batch->id,
                'reported_at' => now(),
            ]);

            $materialCost = 0.0;

            foreach ($required as $itemId => $quantity) {
                $tank = $tanks[$itemId];

                // FOAM-04: snapshot the tank's weighted average now. Later refills
                // move that average, and this run's cost must not move with it.
                $unitCost = $tank !== null ? (float) $tank->weighted_avg_unit_cost : 0.0;

                ConsumptionLine::create([
                    'consumption_report_id' => $report->id,
                    'tank_stock_id' => $tank?->id,
                    'chemical_inventory_item_id' => $itemId,
                    'quantity_consumed' => $quantity,
                    'unit_cost_at_consumption' => $unitCost,
                ]);

                $materialCost += $quantity * $unitCost;

                if ($quantity === 0.0 || $tank === null) {
                    continue;
                }

                // FOAM-10: decrement the tank and leave a movement behind it.
                $tank->quantity_on_hand = round((float) $tank->quantity_on_hand - $quantity, 4);
                $tank->save();

                InventoryMovement::create([
                    'operating_unit_id' => $unitId,
                    'sku' => $tank->chemicalItem?->sku ?? 'BULK-CHEM',
                    'movement_type' => 'consumption',
                    'quantity_delta' => -$quantity,
                    'unit_cost' => $unitCost,
                    'reason' => 'foam_batch_consumption',
                    'reference_document_type' => 'ProductionBatch',
                    'reference_id' => $batch->id,
                ]);
            }

            // Material cost is the batch's, not the report's — it is what gets
            // apportioned across blocks on close.
            $batch->material_cost = round($materialCost, 4);
            $batch->save();

            return $report->fresh(['lines']);
        });
    }
}
