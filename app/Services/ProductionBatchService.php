<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductionBatchStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\InventoryMovement;
use App\Models\ProductionBatch;
use App\Models\Scopes\OperatingUnitScope;
use App\Models\StockLot;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductionBatchService
{
    /**
     * Compose the human-readable block label: {sequence}-{pressure}-{operation}.
     *
     * This string is a label, never an identity. Every component is also stored
     * as its own column, and nothing may parse this back to recover data.
     */
    public function composeLotNumber(int $sequence, int $pressure, int $operationNumber): string
    {
        return sprintf('%03d-%d-%d', $sequence, $pressure, $operationNumber);
    }

    /**
     * Scrap carries no block code — it is not serialized. It gets a plainly
     * distinguishable number so it can never be mistaken for a block on a label
     * or in a stock listing.
     */
    public function composeScrapLotNumber(ProductionBatch $batch, int $index): string
    {
        return sprintf('SCRAP-%d-%02d', $batch->operation_number, $index);
    }

    /**
     * The operation number the operator is expected to enter next.
     *
     * Counts soft-deleted batches: a deleted batch may still have labelled
     * blocks in the yard, so its number remains in physical circulation.
     *
     * Deliberately bypasses the operating-unit scope. Operation numbers are
     * globally unique (§3), so a unit-filtered MAX would let two units both
     * arrive at the same "next" number and collide on the unique index.
     */
    public function nextExpectedOperationNumber(): int
    {
        return ((int) ProductionBatch::withTrashed()
            ->withoutGlobalScope(OperatingUnitScope::class)
            ->max('operation_number')) + 1;
    }

    /**
     * Advance a batch one step along its lifecycle (Phase 04 §4.4).
     *
     * The lifecycle is strictly linear, so this only ever moves forward by one
     * state and rejects anything else — including no-op transitions to the
     * current state, which usually mean a double-submitted button.
     */
    public function transition(ProductionBatch $batch, ProductionBatchStatus $target): ProductionBatch
    {
        return DB::transaction(function () use ($batch, $target): ProductionBatch {
            $locked = ProductionBatch::whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->status;

            if (! $current->canTransitionTo($target)) {
                $allowed = array_map(fn (ProductionBatchStatus $s) => $s->value, $current->allowedNext());

                throw new InvalidStateTransitionException(
                    $allowed === []
                        ? "Operation {$locked->operation_number} is closed and cannot change state."
                        : "Cannot move operation {$locked->operation_number} from {$current->value} to {$target->value}; expected ".implode(' or ', $allowed).'.'
                );
            }

            $this->guardTransition($locked, $target);

            $locked->status = $target;
            $locked->save();

            // FOAM-07: on close the run's material cost is spread across its
            // blocks by volume share, so a bigger block carries more of it.
            if ($target === ProductionBatchStatus::Closed) {
                $this->apportionMaterialCost($locked);
            }

            return $locked->fresh();
        });
    }

    /**
     * FOAM-07: spread the run's material cost across its blocks by volume share.
     *
     *   block_unit_cost = batch_material_cost × (block.volume_m3 / total_block_volume)
     *
     * Scrap volume is deliberately excluded from the denominator. Scrap is not
     * inventory and carries no cost, so including it would quietly under-cost
     * every real block and leave part of the material spend unattached to
     * anything.
     *
     * The final block absorbs the rounding remainder so the apportioned costs sum
     * back to the batch total exactly, rather than drifting a few dirhams.
     */
    public function apportionMaterialCost(ProductionBatch $batch): void
    {
        $materialCost = (float) $batch->material_cost;

        if ($materialCost <= 0.0) {
            return;
        }

        // Blocks only: scrap enters at zero cost and must stay out of the
        // denominator, or every real block would be under-costed.
        $blocks = $batch->blocks()->orderBy('sequence_in_batch')->get();
        $totalVolume = (float) $blocks->sum(fn (StockLot $lot) => (float) $lot->volume_m3);

        if ($blocks->isEmpty() || $totalVolume <= 0.0) {
            return;
        }

        $allocated = 0.0;
        $lastIndex = $blocks->count() - 1;

        foreach ($blocks as $index => $block) {
            if ($index === $lastIndex) {
                $cost = round($materialCost - $allocated, 4);
            } else {
                $cost = round($materialCost * ((float) $block->volume_m3 / $totalVolume), 4);
                $allocated += $cost;
            }

            $block->unit_cost = $cost;
            $block->save();
        }
    }

    /**
     * State-specific preconditions that go beyond ordering.
     */
    private function guardTransition(ProductionBatch $batch, ProductionBatchStatus $target): void
    {
        // FOAM-05: a batch cannot be declared graded while blocks are missing
        // their measured pressure, and cannot close with no output at all.
        if ($target === ProductionBatchStatus::Graded) {
            // Scrap is never graded, so it is excluded from both checks.
            $blockCount = $batch->blocks()->count();

            if ($blockCount === 0) {
                throw new InvalidStateTransitionException(
                    "Operation {$batch->operation_number} has no registered blocks to grade."
                );
            }

            $ungraded = $batch->blocks()->whereNull('pressure')->count();

            if ($ungraded > 0) {
                throw new InvalidStateTransitionException(
                    "Operation {$batch->operation_number} still has {$ungraded} ungraded block(s)."
                );
            }
        }
    }

    /**
     * Register the output of a run from its production report.
     *
     * The paper sheet records blocks as dimension groups with counts, but every
     * block is individually labelled — so each group is expanded into `count`
     * serialized lots, each with its own sequence and printable code.
     *
     * Scrap groups (فاصل / بداية) create no lots and consume no sequence, but
     * their volume is accumulated onto the batch so material yield reconciles.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array{blocks: array<int, StockLot>, scrap_volume_m3: float, batch: ProductionBatch}
     */
    public function registerBlocks(ProductionBatch $batch, array $groups): array
    {
        return DB::transaction(function () use ($batch, $groups): array {
            // Reserve sequence ranges against a locked row so concurrent
            // registrations on the same batch cannot interleave.
            $locked = ProductionBatch::whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            $blocks = [];
            $scrapLots = [];
            $scrapVolume = 0.0;
            $width = (float) $locked->bun_width_m;

            // Scrap lots are numbered per batch, independently of block sequences.
            $scrapIndex = StockLot::withTrashed()
                ->where('production_batch_id', $locked->id)
                ->whereNull('sequence_in_batch')
                ->count() + 1;

            foreach ($groups as $group) {
                $count = (int) $group['count'];
                $length = (float) $group['length_m'];
                $height = (float) $group['height_m'];
                $unitVolume = round($length * $width * $height, 4);

                if (($group['kind'] ?? 'block') === 'scrap') {
                    $groupVolume = round($unitVolume * $count, 4);
                    $scrapVolume += $groupVolume;

                    // Scrap is real material and enters stock, but at zero cost:
                    // the run's whole material spend is carried by the blocks
                    // (see apportionMaterialCost). Standard byproduct treatment.
                    //
                    // It consumes no sequence number — it is not serialized, so it
                    // gets no block code and must not create gaps in the printed
                    // ones.
                    $scrapLot = StockLot::create([
                        'inventory_item_id' => $group['inventory_item_id'],
                        'warehouse_id' => $group['warehouse_id'],
                        'production_batch_id' => $locked->id,
                        'lot_number' => $this->composeScrapLotNumber($locked, $scrapIndex++),
                        // Quantity is total volume, not a piece count. Dimensions are
                        // deliberately left off: with count > 1 they describe one
                        // piece, which would contradict the total in quantity.
                        'quantity' => $groupVolume,
                        'unit_cost' => 0,
                        'status' => 'available',
                    ]);

                    InventoryMovement::create([
                        'operating_unit_id' => $locked->operating_unit_id,
                        'stock_lot_id' => $scrapLot->id,
                        'to_warehouse_id' => $scrapLot->warehouse_id,
                        'sku' => $scrapLot->inventoryItem?->sku ?? 'FOAM-SCRAP',
                        'movement_type' => 'byproduct_yield',
                        'quantity_delta' => $groupVolume,
                        'unit_cost' => 0,
                        'reason' => 'foam_run_scrap',
                        'reference_document_type' => 'ProductionBatch',
                        'reference_id' => $locked->id,
                    ]);

                    $scrapLots[] = $scrapLot;

                    continue;
                }

                if (! isset($group['pressure'])) {
                    throw new InvalidArgumentException('Pressure is required for block groups; no code can be composed without it.');
                }

                $pressure = (int) $group['pressure'];
                $startSequence = (int) $locked->next_sequence;

                for ($offset = 0; $offset < $count; $offset++) {
                    $sequence = $startSequence + $offset;

                    $lot = StockLot::create([
                        'inventory_item_id' => $group['inventory_item_id'],
                        'warehouse_id' => $group['warehouse_id'],
                        'production_batch_id' => $locked->id,
                        'sequence_in_batch' => $sequence,
                        'pressure' => $pressure,
                        'lot_number' => $this->composeLotNumber($sequence, $pressure, (int) $locked->operation_number),
                        'quantity' => 1,
                        'length_m' => $length,
                        // Bun width is a machine setting held on the batch, copied down
                        // rather than re-entered per row.
                        'width_m' => $width,
                        'height_m' => $height,
                        'unit_cost' => $group['unit_cost'] ?? 0,
                        'grade' => $group['grade'] ?? 'standard',
                        'status' => 'available',
                        'attribute_values' => isset($group['color']) ? ['color' => $group['color']] : null,
                    ]);

                    // INV-06: stock only ever appears through a movement, so the
                    // block's arrival in inventory is recorded as production output
                    // traceable back to the batch that made it.
                    InventoryMovement::create([
                        'operating_unit_id' => $locked->operating_unit_id,
                        'stock_lot_id' => $lot->id,
                        'to_warehouse_id' => $lot->warehouse_id,
                        'sku' => $lot->inventoryItem?->sku ?? 'FOAM-BLOCK',
                        'movement_type' => 'production_output',
                        'quantity_delta' => 1,
                        'unit_cost' => (float) $lot->unit_cost,
                        'reason' => 'foam_block_registered',
                        'reference_document_type' => 'ProductionBatch',
                        'reference_id' => $locked->id,
                    ]);

                    $blocks[] = $lot;
                }

                $locked->next_sequence = $startSequence + $count;
            }

            if ($scrapVolume > 0.0) {
                $locked->scrap_volume_m3 = round((float) $locked->scrap_volume_m3 + $scrapVolume, 4);
            }

            $locked->save();

            return [
                'blocks' => $blocks,
                'scrap_lots' => $scrapLots,
                'scrap_volume_m3' => round($scrapVolume, 4),
                'batch' => $locked->fresh(),
            ];
        });
    }
}
