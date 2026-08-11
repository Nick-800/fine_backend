<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\TankStock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TankStockService
{
    /**
     * Refill a chemical bulk tank and recalculate Weighted-Average Cost (WAC).
     */
    public function refill(
        string $chemicalItemId,
        string $operatingUnitId,
        float $refillQty,
        float $refillUnitCost,
        ?string $referenceId = null
    ): TankStock {
        if ($refillQty <= 0) {
            throw new InvalidArgumentException('Refill quantity must be greater than zero.');
        }

        if ($refillUnitCost < 0) {
            throw new InvalidArgumentException('Refill unit cost cannot be negative.');
        }

        return DB::transaction(function () use ($chemicalItemId, $operatingUnitId, $refillQty, $refillUnitCost, $referenceId) {
            $tank = TankStock::where('chemical_inventory_item_id', $chemicalItemId)
                ->where('operating_unit_id', $operatingUnitId)
                ->lockForUpdate()
                ->first();

            if (! $tank) {
                $tank = new TankStock([
                    'chemical_inventory_item_id' => $chemicalItemId,
                    'operating_unit_id' => $operatingUnitId,
                    'quantity_on_hand' => 0.0000,
                    'weighted_avg_unit_cost' => 0.0000,
                ]);
            }

            $oldQty = (float) $tank->quantity_on_hand;
            $oldWac = (float) $tank->weighted_avg_unit_cost;

            $totalQty = $oldQty + $refillQty;
            $newWac = $totalQty > 0
                ? (($oldQty * $oldWac) + ($refillQty * $refillUnitCost)) / $totalQty
                : 0.0;

            $tank->quantity_on_hand = round($totalQty, 4);
            $tank->weighted_avg_unit_cost = round($newWac, 4);
            $tank->save();

            // Record inventory movement
            InventoryMovement::create([
                'operating_unit_id' => $operatingUnitId,
                'sku' => $tank->chemicalItem?->sku ?? 'BULK-CHEM',
                'movement_type' => 'receipt',
                'quantity_delta' => round($refillQty, 4),
                'unit_cost' => round($refillUnitCost, 4),
                'reason' => 'tank_refill',
                'reference_document_type' => 'TankRefill',
                'reference_id' => $referenceId,
            ]);

            return $tank;
        });
    }
}
