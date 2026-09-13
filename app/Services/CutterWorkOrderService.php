<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CutterWorkOrderStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\ByproductYield;
use App\Models\CutterWorkOrder;
use App\Models\CutterWorkOrderLine;
use App\Models\FoamBlockConsumption;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CutterWorkOrderService
{
    /**
     * A block whose remaining volume is within this fraction of the template is
     * treated as fully consumed — there is no usable offcut worth tracking.
     */
    private const FULL_CONSUMPTION_TOLERANCE = 0.02;

    public function __construct(private readonly AccountingService $accountingService) {}

    /**
     * CUT-block-sale: create the order and atomically reserve the chosen
     * precut block. The block's measurements + unit_cost are snapshotted on
     * the order so the price is locked in even if the block's unit_cost
     * changes later. The block's status flips to 'reserved' (visible in
     * inventory as "in use") but its quantity is preserved — the cut hasn't
     * happened yet.
     */
    public function createWithBlock(array $orderAttrs, ?StockLot $block = null): CutterWorkOrder
    {
        return DB::transaction(function () use ($orderAttrs, $block) {
            $order = CutterWorkOrder::create($orderAttrs);

            if ($block === null) {
                return $order;
            }

            $locked = StockLot::whereKey($block->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'available') {
                throw new InvalidArgumentException(
                    "Block {$locked->lot_number} is {$locked->status} and cannot be reserved."
                );
            }

            $locked->status = 'reserved';
            $locked->save();

            $unitCost = (float) $locked->unit_cost;

            $order->stock_lot_id = $locked->id;
            $order->block_unit_cost_snapshot = $unitCost;
            $order->block_length_m_snapshot = $locked->length_m;
            $order->block_width_m_snapshot = $locked->width_m;
            $order->block_height_m_snapshot = $locked->height_m;
            $order->block_volume_m3_snapshot = $locked->volume_m3;
            $order->wip_cost = round($unitCost, 4);
            $order->save();

            $this->accountingService->postJournal(
                "Block {$locked->lot_number} reserved for cutter order {$order->order_number}",
                [
                    [
                        'account_code' => '1122',
                        'debit' => $unitCost,
                        'operating_unit_id' => $order->operating_unit_id,
                    ],
                    [
                        'account_code' => '1131',
                        'credit' => $unitCost,
                        'operating_unit_id' => $order->operating_unit_id,
                    ],
                ],
                'CutterWorkOrder',
                $order->id,
                $order->operatingUnit?->company_id,
            );

            return $order->refresh();
        });
    }

    /**
     * Blocks a cutter manager may choose from for a line.
     *
     * CUT-02: this filters and presents. It deliberately does not score, rank by
     * fit, or pick — the manager is standing in front of the blocks and knows
     * things the system does not (damage, position in the yard, what is easy to
     * reach). Auto-assignment would be guessing with authority.
     */
    public function availableBlocksFor(CutterWorkOrderLine $line, int $perPage = 25): LengthAwarePaginator
    {
        if (! $line->hasTemplate()) {
            throw new InvalidArgumentException(
                'Assign a template shape before selecting blocks; there is nothing to size the selection against.'
            );
        }

        return StockLot::query()
            ->with(['inventoryItem', 'warehouse'])
            ->where('status', 'available')
            ->whereHas('inventoryItem', fn (Builder $q) => $q->where('item_type', 'foam_block'))
            ->where('volume_m3', '>=', $line->requiredVolumeM3())
            ->whereDoesntHave('cutterConsumption')
            // Smallest adequate block first: cutting a large block for a small
            // template wastes the difference.
            ->orderBy('volume_m3')
            ->paginate($perPage);
    }

    /**
     * Inventory-wide list of available foam blocks for the create-order picker.
     * Doesn't require a line context (unlike availableBlocksFor).
     */
    public function availableFoamBlocks(int $perPage = 50): LengthAwarePaginator
    {
        return StockLot::query()
            ->with(['inventoryItem', 'warehouse'])
            ->where('status', 'available')
            ->whereHas('inventoryItem', fn (Builder $q) => $q->where('item_type', 'foam_block'))
            ->whereDoesntHave('cutterConsumption')
            ->orderBy('volume_m3')
            ->paginate($perPage);
    }

    /**
     * Record the manager's choice and move the block's value into the order.
     *
     * The whole block leaves stock either way (v1: no clean-remainder
     * restocking). Its cost splits into the template's share and the offcut's,
     * and those two always sum back to the block's full cost so nothing is left
     * unaccounted between the block and its outputs.
     */
    public function selectBlock(CutterWorkOrderLine $line, StockLot $block): FoamBlockConsumption
    {
        return DB::transaction(function () use ($line, $block): FoamBlockConsumption {
            $order = $line->cutterWorkOrder;

            if (! $order->status->acceptsBlockSelection()) {
                throw new InvalidStateTransitionException(
                    "Blocks cannot be selected while order {$order->order_number} is {$order->status->value}."
                );
            }

            if (! $line->hasTemplate()) {
                throw new InvalidArgumentException('Assign a template shape before selecting blocks.');
            }

            $locked = StockLot::whereKey($block->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'available') {
                throw new InvalidArgumentException(
                    "Block {$locked->lot_number} is {$locked->status} and cannot be cut."
                );
            }

            $blockVolume = (float) $locked->volume_m3;
            $required = $line->requiredVolumeM3();

            if ($blockVolume < $required) {
                throw new InvalidArgumentException(
                    "Block {$locked->lot_number} holds {$blockVolume} m³, short of the {$required} m³ this line needs."
                );
            }

            $blockCost = (float) $locked->unit_cost;
            $isFull = ($blockVolume - $required) <= ($required * self::FULL_CONSUMPTION_TOLERANCE);

            // CUT-07 / CUT-08
            $consumedCost = $isFull ? $blockCost : round($blockCost * ($required / $blockVolume), 4);
            $remainderCost = round($blockCost - $consumedCost, 4);

            $consumption = FoamBlockConsumption::create([
                'cutter_work_order_line_id' => $line->id,
                'stock_lot_id' => $locked->id,
                'block_volume_m3' => $blockVolume,
                'volume_consumed_m3' => $required,
                'consumption_type' => $isFull ? 'full' : 'partial',
                'consumed_cost' => $consumedCost,
                'remainder_cost' => $remainderCost,
            ]);

            $locked->status = 'consumed';
            $locked->quantity = 0;
            $locked->save();

            // Movement 1 of 3 (CUT-09): the block leaves foam inventory.
            InventoryMovement::create([
                'operating_unit_id' => $order->operating_unit_id,
                'stock_lot_id' => $locked->id,
                'from_warehouse_id' => $locked->warehouse_id,
                'sku' => $locked->inventoryItem?->sku ?? 'FOAM-BLOCK',
                'movement_type' => 'consumption',
                'quantity_delta' => -1,
                'unit_cost' => $blockCost,
                'reason' => 'cutter_consumption',
                'reference_document_type' => 'CutterWorkOrder',
                'reference_id' => $order->id,
            ]);

            // The block's whole value moves into the order, offcut included —
            // the offcut becomes byproduct rather than disappearing.
            $order->wip_cost = round((float) $order->wip_cost + $blockCost, 4);
            $order->save();

            $this->accountingService->postJournal(
                "Block {$locked->lot_number} into cutter order {$order->order_number}",
                [
                    ['account_code' => '1122', 'debit' => $blockCost, 'operating_unit_id' => $order->operating_unit_id],
                    ['account_code' => '1131', 'credit' => $blockCost, 'operating_unit_id' => $order->operating_unit_id],
                ],
                'CutterWorkOrder',
                $order->id,
                $order->operatingUnit?->company_id,
            );

            return $consumption;
        });
    }

    /**
     * CUT-03 / CUT-04: record the weigh-in.
     *
     * Zero is a valid, meaningful answer — it says someone looked and there was
     * nothing to salvage. A missing row says nobody checked, which is why the
     * order cannot move past this point without one.
     */
    public function recordWeighIn(
        CutterWorkOrder $order,
        float $weightKg,
        ?string $byproductItemId,
        ?string $warehouseId,
        ?User $weighedBy = null,
    ): ByproductYield {
        if ($weightKg < 0) {
            throw new InvalidArgumentException('Byproduct weight cannot be negative.');
        }

        if ($order->status !== CutterWorkOrderStatus::AwaitingByproductWeighIn) {
            throw new InvalidStateTransitionException(
                "Order {$order->order_number} is {$order->status->value}; a weigh-in belongs at the awaiting_byproduct_weigh_in step."
            );
        }

        return DB::transaction(function () use ($order, $weightKg, $byproductItemId, $warehouseId, $weighedBy): ByproductYield {
            // The offcut carries the part of the block cost the template did not.
            $remainderCost = round((float) $order->consumptions()->sum('remainder_cost'), 4);

            $lot = null;

            if ($weightKg > 0 && $byproductItemId !== null && $warehouseId !== null) {
                $lot = StockLot::create([
                    'inventory_item_id' => $byproductItemId,
                    'warehouse_id' => $warehouseId,
                    'lot_number' => 'BYP-'.$order->order_number.'-'.now()->format('YmdHis'),
                    'quantity' => $weightKg,
                    'weight_kg' => $weightKg,
                    'unit_cost' => $weightKg > 0 ? round($remainderCost / $weightKg, 4) : 0,
                    'status' => 'available',
                ]);

                // Movement 2 of 3: byproduct fill enters cutter inventory.
                InventoryMovement::create([
                    'operating_unit_id' => $order->operating_unit_id,
                    'stock_lot_id' => $lot->id,
                    'to_warehouse_id' => $warehouseId,
                    'sku' => InventoryItem::find($byproductItemId)?->sku ?? 'BYPRODUCT-FILL',
                    'movement_type' => 'byproduct_yield',
                    'quantity_delta' => $weightKg,
                    'unit_cost' => $lot->unit_cost,
                    'reason' => 'cutter_byproduct',
                    'reference_document_type' => 'CutterWorkOrder',
                    'reference_id' => $order->id,
                ]);
            }

            return ByproductYield::create([
                'cutter_work_order_id' => $order->id,
                'stock_lot_id' => $lot?->id,
                'weight_kg' => $weightKg,
                // Nothing salvaged means the offcut was genuinely lost, so its
                // value stays in WIP and lands on the cut pieces instead.
                'yield_cost' => $weightKg > 0 ? $remainderCost : 0,
                'weighed_by_user_id' => $weighedBy?->id,
                'weighed_at' => now(),
            ]);
        });
    }

    public function transition(CutterWorkOrder $order, CutterWorkOrderStatus $target): CutterWorkOrder
    {
        return DB::transaction(function () use ($order, $target): CutterWorkOrder {
            $locked = CutterWorkOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->status;

            if (! $current->canTransitionTo($target)) {
                $allowed = array_map(fn (CutterWorkOrderStatus $s) => $s->value, $current->allowedNext());

                throw new InvalidStateTransitionException(
                    $allowed === []
                        ? "Order {$locked->order_number} is invoiced and cannot change state."
                        : "Cannot move order {$locked->order_number} from {$current->value} to {$target->value}; expected ".implode(' or ', $allowed).'.'
                );
            }

            $this->guardTransition($locked, $target);

            $locked->status = $target;
            $locked->save();

            // CUT-block-sale: the cut moment. The reserved block is now
            // physically consumed (status flips to consumed, quantity zeroed)
            // and the per-line FoamBlockConsumption record is created. Legacy
            // orders without a reserved block skip this — selectBlock will
            // have created the consumption record already.
            if ($target === CutterWorkOrderStatus::InProduction && $locked->stock_lot_id) {
                $this->consumeReservedBlock($locked);
            }

            if ($target === CutterWorkOrderStatus::Completed) {
                $this->produceOutputs($locked);
            }

            return $locked->fresh(['lines', 'byproductYields']);
        });
    }

    /**
     * Flip the reserved block to consumed and create the FoamBlockConsumption
     * record. Called automatically when a block-attached order advances to
     * in_production. Idempotent: skips if the block is no longer reserved.
     */
    public function consumeReservedBlock(CutterWorkOrder $order): ?FoamBlockConsumption
    {
        if ($order->stock_lot_id === null) {
            return null;
        }

        $locked = StockLot::whereKey($order->stock_lot_id)->lockForUpdate()->first();

        if ($locked === null || $locked->status !== 'reserved') {
            return null;
        }

        $firstLine = $order->lines()->orderBy('created_at')->first();

        $consumption = FoamBlockConsumption::create([
            'cutter_work_order_line_id' => $firstLine?->id,
            'stock_lot_id' => $locked->id,
            'block_volume_m3' => (float) $locked->volume_m3,
            'volume_consumed_m3' => (float) $locked->volume_m3,
            'consumption_type' => 'full',
            'consumed_cost' => (float) $locked->unit_cost,
            'remainder_cost' => 0,
        ]);

        $locked->status = 'consumed';
        $locked->quantity = 0;
        $locked->save();

        // Movement 1 of 3 (CUT-09): the block leaves foam inventory.
        InventoryMovement::create([
            'operating_unit_id' => $order->operating_unit_id,
            'stock_lot_id' => $locked->id,
            'from_warehouse_id' => $locked->warehouse_id,
            'sku' => $locked->inventoryItem?->sku ?? 'FOAM-BLOCK',
            'movement_type' => 'consumption',
            'quantity_delta' => -1,
            'unit_cost' => (float) $locked->unit_cost,
            'reason' => 'cutter_consumption',
            'reference_document_type' => 'CutterWorkOrder',
            'reference_id' => $order->id,
        ]);

        return $consumption;
    }

    private function guardTransition(CutterWorkOrder $order, CutterWorkOrderStatus $target): void
    {
        // CUT-01: production cannot start on a line nobody has sized.
        if ($target === CutterWorkOrderStatus::InProduction) {
            $lines = $order->lines()->get();

            if ($lines->isEmpty()) {
                throw new InvalidStateTransitionException(
                    "Order {$order->order_number} has no lines to produce."
                );
            }

            $untemplated = $lines->filter(fn (CutterWorkOrderLine $l) => ! $l->hasTemplate());

            if ($untemplated->isNotEmpty()) {
                throw new InvalidStateTransitionException(
                    "Order {$order->order_number} has {$untemplated->count()} line(s) with no template shape assigned."
                );
            }
        }

        // A cut cannot be reported finished if no block was ever taken for it.
        if ($target === CutterWorkOrderStatus::AwaitingByproductWeighIn
            && $order->consumptions()->count() === 0) {
            throw new InvalidStateTransitionException(
                "Order {$order->order_number} has no block consumption recorded; nothing was cut."
            );
        }

        // CUT-03: the hard gate. Without it the weigh-in is the step everyone
        // skips, and the byproduct silently disappears from the books.
        if ($target === CutterWorkOrderStatus::QualityCheck
            && $order->byproductYields()->count() === 0) {
            throw new InvalidStateTransitionException(
                "Order {$order->order_number} cannot pass to quality check before the byproduct weigh-in is recorded. Enter 0 if there was none."
            );
        }
    }

    /**
     * On completion the order's accumulated material moves out of WIP and into
     * the cut pieces, less whatever the byproduct already absorbed.
     */
    private function produceOutputs(CutterWorkOrder $order): void
    {
        $byproductCost = round((float) $order->byproductYields()->sum('yield_cost'), 4);
        $pieceCost = round((float) $order->wip_cost - $byproductCost, 4);

        $lines = $order->lines()->with('consumptions')->get();
        $totalPieces = (int) $lines->sum('quantity');

        if ($totalPieces === 0) {
            return;
        }

        $costPerPiece = round($pieceCost / $totalPieces, 4);
        $allocated = 0.0;
        $created = 0;

        foreach ($lines as $line) {
            $warehouseId = $line->consumptions->first()?->stockLot?->warehouse_id;

            if ($line->output_inventory_item_id === null || $warehouseId === null) {
                continue;
            }

            for ($i = 1; $i <= $line->quantity; $i++) {
                $created++;
                // Last piece absorbs the rounding remainder so the pieces sum
                // back to the value taken out of WIP.
                $cost = $created === $totalPieces
                    ? round($pieceCost - $allocated, 4)
                    : $costPerPiece;
                $allocated = round($allocated + $costPerPiece, 4);

                // CUT-05: the piece is stocked at its template dimensions, not
                // the shape the client described.
                // CUT-block-sale: each piece carries source_stock_lot_id + the
                // source block's dimensions for traceability. The piece's own
                // physical dims stay template-derived (the cut is smaller than
                // the block); the source dims are reference, not replacement.
                $piece = StockLot::create([
                    'inventory_item_id' => $line->output_inventory_item_id,
                    'warehouse_id' => $warehouseId,
                    'lot_number' => sprintf('CUT-%s-%03d', $order->order_number, $created),
                    'quantity' => 1,
                    'length_m' => $line->template_length_m,
                    'width_m' => $line->template_width_m,
                    'height_m' => $line->template_height_m,
                    'unit_cost' => $cost,
                    'status' => 'available',
                    'source_stock_lot_id' => $order->stock_lot_id,
                    'source_block_length_m' => $order->block_length_m_snapshot,
                    'source_block_width_m' => $order->block_width_m_snapshot,
                    'source_block_height_m' => $order->block_height_m_snapshot,
                ]);

                // Movement 3 of 3: the cut piece enters cutter inventory.
                InventoryMovement::create([
                    'operating_unit_id' => $order->operating_unit_id,
                    'stock_lot_id' => $piece->id,
                    'to_warehouse_id' => $warehouseId,
                    'sku' => $line->outputItem?->sku ?? 'CUT-PIECE',
                    'movement_type' => 'production_output',
                    'quantity_delta' => 1,
                    'unit_cost' => $cost,
                    'reason' => 'cutter_output',
                    'reference_document_type' => 'CutterWorkOrder',
                    'reference_id' => $order->id,
                ]);
            }
        }

        $lines = [];

        if ($pieceCost > 0) {
            $lines[] = ['account_code' => '1132', 'debit' => $pieceCost, 'operating_unit_id' => $order->operating_unit_id];
        }

        if ($byproductCost > 0) {
            $lines[] = ['account_code' => '1133', 'debit' => $byproductCost, 'operating_unit_id' => $order->operating_unit_id];
        }

        $total = round($pieceCost + $byproductCost, 4);

        if ($total <= 0) {
            return;
        }

        $lines[] = ['account_code' => '1122', 'credit' => $total, 'operating_unit_id' => $order->operating_unit_id];

        $this->accountingService->postJournal(
            "Cutter order {$order->order_number} completed",
            $lines,
            'CutterWorkOrder',
            $order->id,
            $order->operatingUnit?->company_id,
        );

        $order->wip_cost = 0;
        $order->save();
    }
}
