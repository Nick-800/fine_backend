<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\StockAdjustmentRequest;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockAdjustmentService
{
    /**
     * Create a pending stock adjustment request.
     */
    public function createRequest(
        string $operatingUnitId,
        string $stockLotId,
        string $reasonCode,
        float $quantityDelta,
        ?string $notes,
        User $requestedBy
    ): StockAdjustmentRequest {
        $allowedReasons = ['audit_reconciliation', 'spill_loss', 'damage', 'expired'];
        if (! in_array($reasonCode, $allowedReasons, true)) {
            throw new InvalidArgumentException("Invalid reason code {$reasonCode}. Allowed: ".implode(', ', $allowedReasons));
        }

        if ($quantityDelta == 0.0) {
            throw new InvalidArgumentException('Quantity delta cannot be zero.');
        }

        return StockAdjustmentRequest::create([
            'operating_unit_id' => $operatingUnitId,
            'stock_lot_id' => $stockLotId,
            'reason_code' => $reasonCode,
            'quantity_delta' => round($quantityDelta, 4),
            'notes' => $notes,
            'status' => 'pending',
            'requested_by_user_id' => $requestedBy->id,
        ]);
    }

    /**
     * Unit Manager approves adjustment request and posts movement to stock ledger.
     */
    public function approve(StockAdjustmentRequest $request, User $approvedBy): StockAdjustmentRequest
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException("Cannot approve request in {$request->status} status.");
        }

        return DB::transaction(function () use ($request, $approvedBy) {
            $request->status = 'approved';
            $request->approved_by_user_id = $approvedBy->id;
            $request->approved_at = now();
            $request->save();

            // Update StockLot quantity
            $stockLot = StockLot::lockForUpdate()->findOrFail($request->stock_lot_id);
            $newQty = (float) $stockLot->quantity + (float) $request->quantity_delta;
            if ($newQty < 0) {
                throw new InvalidArgumentException('Adjustment would cause stock lot quantity to fall below zero.');
            }

            $stockLot->quantity = round($newQty, 4);
            if ($newQty == 0.0 && $stockLot->inventoryItem?->item_type === 'foam_block') {
                $stockLot->status = 'consumed';
            }
            $stockLot->save();

            // Record immutable stock movement
            InventoryMovement::create([
                'operating_unit_id' => $request->operating_unit_id,
                'stock_lot_id' => $stockLot->id,
                'from_warehouse_id' => $request->quantity_delta < 0 ? $stockLot->warehouse_id : null,
                'to_warehouse_id' => $request->quantity_delta > 0 ? $stockLot->warehouse_id : null,
                'sku' => $stockLot->inventoryItem?->sku ?? 'ITEM',
                'movement_type' => 'adjustment',
                'quantity_delta' => (float) $request->quantity_delta,
                'unit_cost' => (float) $stockLot->unit_cost,
                'reason' => $request->reason_code,
                'reference_document_type' => 'StockAdjustmentRequest',
                'reference_id' => $request->id,
            ]);

            return $request;
        });
    }

    /**
     * Reject adjustment request.
     */
    public function reject(StockAdjustmentRequest $request, User $rejectedBy): StockAdjustmentRequest
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException("Cannot reject request in {$request->status} status.");
        }

        $request->status = 'rejected';
        $request->approved_by_user_id = $rejectedBy->id;
        $request->approved_at = now();
        $request->save();

        return $request;
    }
}
