<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\StockLot;
use App\Models\TankStock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TankStockService
{
    public function __construct(
        private readonly StockLotService $stockLotService,
        private readonly AccountingService $accountingService,
    ) {}

    /**
     * Pour a source lot into the tank: the balanced version of a refill.
     *
     * A refill is a *transfer*, not a receipt. The old path credited the tank from
     * an operator-typed quantity and cost while never decrementing anything, so
     * pouring a 40L barrel in left the barrel still showing 40L and invented
     * stock. Both legs are recorded here.
     *
     * Cost comes from the source lot, never from client input — the operator can
     * no longer type a number that disagrees with what was actually paid.
     *
     * @param  float|null  $drawQuantity  measure UOM; null means draw whole containers
     * @param  int|null  $drawContainers  whole containers, expanded via container_capacity
     */
    public function refillFromLot(
        StockLot $sourceLot,
        ?float $drawQuantity = null,
        ?int $drawContainers = null,
        ?string $referenceId = null,
    ): TankStock {
        if (($drawQuantity === null) === ($drawContainers === null)) {
            throw new InvalidArgumentException('Provide exactly one of draw_quantity or draw_containers.');
        }

        return DB::transaction(function () use ($sourceLot, $drawQuantity, $drawContainers, $referenceId): TankStock {
            $item = $sourceLot->inventoryItem;

            if ($item === null) {
                throw new InvalidArgumentException('Source lot has no inventory item.');
            }

            if ($drawContainers !== null) {
                if (! $item->tracksContainers()) {
                    throw new InvalidArgumentException(
                        "{$item->sku} has no container capacity set, so it cannot be drawn by the container."
                    );
                }

                $drawQuantity = round($drawContainers * (float) $item->container_capacity, 4);
            }

            $unitId = $sourceLot->warehouse?->operating_unit_id;

            if ($unitId === null) {
                throw new InvalidArgumentException('Source lot has no warehouse, so its operating unit is unknown.');
            }

            // Cost is read off the lot before it is drawn down.
            $unitCost = (float) $sourceLot->unit_cost;

            $result = $this->stockLotService->drawFromLot(
                $sourceLot,
                (float) $drawQuantity,
                'tank_refill',
                'TankStock',
                $referenceId,
            );

            return $this->applyRefill(
                $item->id,
                $unitId,
                (float) $drawQuantity,
                $unitCost,
                $referenceId,
                $result['lot']->id,
                'tank_refill',
            );
        });
    }

    /**
     * Credit a tank without a source lot.
     *
     * Kept for opening balances and corrections only. Deliberately distinguished
     * from a real refill by its movement reason so unsourced credits stay
     * auditable rather than looking like received stock.
     *
     * Unlike refillFromLot (a 1110→1110 transfer needing no journal), this
     * path creates value from nothing, so the ledger must receive it too:
     * DR 1110 / CR 3100, the same treatment as an opening-balance stock
     * intake. Without it every manual charge widened the gap between the
     * stock ledger and the books.
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

        return DB::transaction(function () use ($chemicalItemId, $operatingUnitId, $refillQty, $refillUnitCost, $referenceId): TankStock {
            $tank = $this->applyRefill(
                $chemicalItemId,
                $operatingUnitId,
                $refillQty,
                $refillUnitCost,
                $referenceId,
                null,
                // Unsourced: distinguished from a real refill so a credit with no
                // stock behind it can be picked out of the ledger.
                'tank_adjustment',
            );

            $value = round($refillQty * $refillUnitCost, 4);

            if ($value > 0) {
                $this->accountingService->postJournal(
                    "Tank opening balance / correction — {$tank->chemicalItem?->sku}",
                    [
                        [
                            'account_code' => '1110', // Raw Material Inventory
                            'debit' => $value,
                            'operating_unit_id' => $operatingUnitId,
                            'memo' => "{$refillQty} @ {$refillUnitCost}",
                        ],
                        [
                            'account_code' => '3100', // Retained Earnings
                            'credit' => $value,
                            'operating_unit_id' => $operatingUnitId,
                        ],
                    ],
                    'TankStock',
                    $tank->id,
                    $tank->operatingUnit?->company_id,
                );
            }

            return $tank;
        });
    }

    /**
     * Credit the tank and roll its weighted-average cost forward.
     *
     *   new_avg = (old_qty × old_avg + add_qty × add_cost) / (old_qty + add_qty)
     */
    private function applyRefill(
        string $chemicalItemId,
        string $operatingUnitId,
        float $quantity,
        float $unitCost,
        ?string $referenceId,
        ?string $sourceLotId,
        string $reason,
    ): TankStock {
        return DB::transaction(function () use ($chemicalItemId, $operatingUnitId, $quantity, $unitCost, $referenceId, $sourceLotId, $reason): TankStock {
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

            $totalQty = $oldQty + $quantity;
            $newWac = $totalQty > 0
                ? (($oldQty * $oldWac) + ($quantity * $unitCost)) / $totalQty
                : 0.0;

            $tank->quantity_on_hand = round($totalQty, 4);
            $tank->weighted_avg_unit_cost = round($newWac, 4);
            $tank->save();

            InventoryMovement::create([
                'operating_unit_id' => $operatingUnitId,
                'stock_lot_id' => $sourceLotId,
                'sku' => $tank->chemicalItem?->sku ?? 'BULK-CHEM',
                'movement_type' => 'receipt',
                'quantity_delta' => round($quantity, 4),
                'unit_cost' => round($unitCost, 4),
                'reason' => $reason,
                'reference_document_type' => 'TankRefill',
                'reference_id' => $referenceId,
            ]);

            return $tank;
        });
    }
}
