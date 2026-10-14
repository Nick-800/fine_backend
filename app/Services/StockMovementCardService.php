<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Warehouse;

final class StockMovementCardService
{
    /**
     * @return array{
     *     item: array{id: string, name: string, code: string, unit: string|null},
     *     from: string|null,
     *     to: string|null,
     *     warehouse_id: string|null,
     *     opening_balance: float,
     *     rows: array<int, array{
     *         id: string,
     *         date: string|null,
     *         movement_type: string,
     *         group: string,
     *         reference_document_type: string|null,
     *         reference_id: string|null,
     *         reason: string,
     *         warehouse: array{id: string, name: string}|null,
     *         in: float,
     *         out: float,
     *         balance: float,
     *         unit_cost: float,
     *     }>,
     *     totals: array{in: float, out: float},
     *     closing_balance: float,
     * }
     */
    public function movementCard(
        string $itemId,
        ?string $from = null,
        ?string $to = null,
        ?string $warehouseId = null,
        ?string $operatingUnitId = null,
    ): array {
        $item = InventoryItem::findOrFail($itemId);

        if ($warehouseId !== null) {
            Warehouse::findOrFail($warehouseId);
        }

        $openingBalance = 0.0;
        if ($from !== null) {
            $openingQuery = $this->baseMovementQuery($item, $operatingUnitId, $warehouseId)
                ->whereDate('inventory_movements.created_at', '<', $from)
                ->orderBy('inventory_movements.created_at')
                ->orderBy('inventory_movements.id');

            $openingMovements = $openingQuery->get();
            foreach ($openingMovements as $mov) {
                $openingBalance += $this->calculateDeltaForWarehouse($mov, $warehouseId);
            }
        }

        $periodQuery = $this->baseMovementQuery($item, $operatingUnitId, $warehouseId)
            ->with(['fromWarehouse', 'toWarehouse'])
            ->orderBy('inventory_movements.created_at')
            ->orderBy('inventory_movements.id');

        if ($from !== null) {
            $periodQuery->whereDate('inventory_movements.created_at', '>=', $from);
        }

        if ($to !== null) {
            $periodQuery->whereDate('inventory_movements.created_at', '<=', $to);
        }

        $movements = $periodQuery->get();

        $runningBalance = $openingBalance;
        $totalIn = 0.0;
        $totalOut = 0.0;
        $rows = [];

        foreach ($movements as $movement) {
            $delta = $this->calculateDeltaForWarehouse($movement, $warehouseId);

            $in = $delta > 0 ? $delta : 0.0;
            $out = $delta < 0 ? abs($delta) : 0.0;

            $totalIn += $in;
            $totalOut += $out;
            $runningBalance += $delta;

            $wh = null;
            if ($warehouseId !== null) {
                if ($movement->to_warehouse_id === $warehouseId) {
                    $wh = $movement->toWarehouse;
                } elseif ($movement->from_warehouse_id === $warehouseId) {
                    $wh = $movement->fromWarehouse;
                }
            } else {
                if ($movement->quantity_delta > 0) {
                    $wh = $movement->toWarehouse ?? $movement->fromWarehouse;
                } else {
                    $wh = $movement->fromWarehouse ?? $movement->toWarehouse;
                }
            }

            $warehouseData = $wh ? ['id' => $wh->id, 'name' => $wh->name] : null;

            $rows[] = [
                'id' => (string) $movement->id,
                'date' => $movement->created_at?->toIso8601String(),
                'movement_type' => (string) $movement->movement_type,
                'group' => $this->resolveGroup($movement),
                'reference_document_type' => $movement->reference_document_type,
                'reference_id' => $movement->reference_id,
                'reason' => (string) $movement->reason,
                'warehouse' => $warehouseData,
                'in' => round($in, 4),
                'out' => round($out, 4),
                'balance' => round($runningBalance, 4),
                'unit_cost' => round((float) $movement->unit_cost, 4),
            ];
        }

        return [
            'item' => [
                'id' => $item->id,
                'name' => $item->name,
                'code' => $item->code,
                'unit' => $item->unit_of_measure,
            ],
            'from' => $from,
            'to' => $to,
            'warehouse_id' => $warehouseId,
            'opening_balance' => round($openingBalance, 4),
            'rows' => $rows,
            'totals' => [
                'in' => round($totalIn, 4),
                'out' => round($totalOut, 4),
            ],
            'closing_balance' => round($runningBalance, 4),
        ];
    }

    private function baseMovementQuery(
        InventoryItem $item,
        ?string $operatingUnitId,
        ?string $warehouseId,
    ) {
        $query = InventoryMovement::query()
            ->where(function ($q) use ($item): void {
                $q->whereHas('stockLot', function ($lotQuery) use ($item): void {
                    $lotQuery->withoutGlobalScopes()->where('inventory_item_id', $item->id);
                })->orWhere('inventory_movements.sku', $item->code);
            });

        if ($operatingUnitId !== null) {
            $query->where('inventory_movements.operating_unit_id', $operatingUnitId);
        }

        if ($warehouseId !== null) {
            $query->where(function ($q) use ($warehouseId): void {
                $q->where('inventory_movements.from_warehouse_id', $warehouseId)
                    ->orWhere('inventory_movements.to_warehouse_id', $warehouseId);
            });
        }

        return $query;
    }

    private function calculateDeltaForWarehouse(InventoryMovement $movement, ?string $warehouseId): float
    {
        $rawDelta = (float) $movement->quantity_delta;

        if ($warehouseId === null) {
            return $rawDelta;
        }

        $isFrom = $movement->from_warehouse_id === $warehouseId;
        $isTo = $movement->to_warehouse_id === $warehouseId;

        if ($isFrom && $isTo) {
            return 0.0;
        }

        if ($isTo) {
            return abs($rawDelta);
        }

        if ($isFrom) {
            return -abs($rawDelta);
        }

        return $rawDelta;
    }

    private function resolveGroup(InventoryMovement $movement): string
    {
        $type = $movement->movement_type;
        $reason = strtolower((string) $movement->reason);
        $refType = (string) $movement->reference_document_type;

        if (str_contains($reason, 'scrap') || str_contains($reason, 'spill') || str_contains($reason, 'damage')) {
            return 'scrap';
        }

        if ($type === 'intake' || $type === 'receipt' || $refType === 'PurchaseOrder') {
            return 'purchase receipt';
        }

        if ($type === 'sale' || ($type === 'issue' && $refType === 'SalesOrder')) {
            return 'sale issue';
        }

        if ($type === 'transfer' || $refType === 'WarehouseTransfer') {
            return 'internal transfer';
        }

        if (
            $type === 'consumption' ||
            $refType === 'CutterWorkOrder' ||
            str_starts_with($reason, 'cutter_') ||
            $refType === 'WorkOrder' ||
            $type === 'production_output'
        ) {
            return 'cutting';
        }

        if ($type === 'byproduct_yield') {
            return 'scrap';
        }

        if ($type === 'adjustment' || str_contains($reason, 'adjustment') || str_contains($reason, 'reconciliation')) {
            return 'adjustment';
        }

        if ($type === 'issue') {
            return 'sale issue';
        }

        return 'other';
    }
}
