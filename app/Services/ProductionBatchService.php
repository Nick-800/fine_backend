<?php

declare(strict_types=1);

namespace App\Services;

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
            $scrapVolume = 0.0;
            $width = (float) $locked->bun_width_m;

            foreach ($groups as $group) {
                $count = (int) $group['count'];
                $length = (float) $group['length_m'];
                $height = (float) $group['height_m'];
                $unitVolume = round($length * $width * $height, 4);

                if (($group['kind'] ?? 'block') === 'scrap') {
                    $scrapVolume += round($unitVolume * $count, 4);

                    continue;
                }

                if (! isset($group['pressure'])) {
                    throw new InvalidArgumentException('Pressure is required for block groups; no code can be composed without it.');
                }

                $pressure = (int) $group['pressure'];
                $startSequence = (int) $locked->next_sequence;

                for ($offset = 0; $offset < $count; $offset++) {
                    $sequence = $startSequence + $offset;

                    $blocks[] = StockLot::create([
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
                }

                $locked->next_sequence = $startSequence + $count;
            }

            if ($scrapVolume > 0.0) {
                $locked->scrap_volume_m3 = round((float) $locked->scrap_volume_m3 + $scrapVolume, 4);
            }

            $locked->save();

            return [
                'blocks' => $blocks,
                'scrap_volume_m3' => round($scrapVolume, 4),
                'batch' => $locked->fresh(),
            ];
        });
    }
}
