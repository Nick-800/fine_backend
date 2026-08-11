<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\StockLot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockLotService
{
    /**
     * Build filtered StockLot query including dynamic JSON attribute filters.
     */
    public function getFilteredLots(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = StockLot::with(['inventoryItem.category', 'warehouse']);

        if (isset($filters['status']) && filled($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['grade']) && filled($filters['grade'])) {
            $query->where('grade', $filters['grade']);
        }

        if (isset($filters['warehouse_id']) && filled($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (isset($filters['production_batch_id']) && filled($filters['production_batch_id'])) {
            $query->where('production_batch_id', $filters['production_batch_id']);
        }

        if (isset($filters['category_id']) && filled($filters['category_id'])) {
            $query->whereHas('inventoryItem', function (Builder $b) use ($filters): void {
                $b->where('category_id', $filters['category_id']);
            });
        }

        // Apply dynamic JSON attribute filters: ?attrs[pressure_kpa][gte]=35
        if (isset($filters['attrs']) && is_array($filters['attrs'])) {
            foreach ($filters['attrs'] as $key => $condition) {
                $safeKey = str_replace("'", '', (string) $key);
                if (is_array($condition)) {
                    foreach ($condition as $op => $val) {
                        if ($val === null || $val === '') {
                            continue;
                        }
                        if ($op === 'gte') {
                            $query->whereRaw("CAST(json_extract(attribute_values, '$.{$safeKey}') AS NUMERIC) >= ?", [(float) $val]);
                        } elseif ($op === 'lte') {
                            $query->whereRaw("CAST(json_extract(attribute_values, '$.{$safeKey}') AS NUMERIC) <= ?", [(float) $val]);
                        } elseif ($op === 'eq') {
                            $query->whereRaw("json_extract(attribute_values, '$.{$safeKey}') = ?", [$val]);
                        }
                    }
                } else {
                    if ($condition !== null && $condition !== '') {
                        $query->whereRaw("json_extract(attribute_values, '$.{$safeKey}') = ?", [$condition]);
                    }
                }
            }
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Query available serialized foam blocks for cutter selection based on minimum volume and grade.
     */
    public function getAvailableForCutting(
        ?float $minVolumeM3 = null,
        ?string $grade = null,
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = StockLot::with(['inventoryItem.category', 'warehouse'])
            ->where('status', 'available')
            ->whereHas('inventoryItem', function (Builder $b): void {
                $b->where('item_type', 'foam_block');
            });

        if ($minVolumeM3 !== null && $minVolumeM3 > 0) {
            $query->where('volume_m3', '>=', $minVolumeM3);
        }

        if ($grade !== null && $grade !== '') {
            $query->where('grade', $grade);
        }

        return $query->orderBy('volume_m3', 'asc')->paginate($perPage);
    }

    /**
     * Process cutter operator completion decision for a consumed foam block lot.
     * Option C: Restock remnant block with manually specified dimensions (L x W x H) OR convert to byproduct fill.
     */
    public function processCutRemnant(
        StockLot $parentLot,
        string $remnantAction,
        ?array $remnantDimensions = null,
        ?float $byproductWeightKg = null
    ): array {
        return DB::transaction(function () use ($parentLot, $remnantAction, $remnantDimensions, $byproductWeightKg) {
            // Lock the parent so the remnant revision counter cannot race.
            $parentLot = StockLot::whereKey($parentLot->getKey())->lockForUpdate()->firstOrFail();

            // Mark parent block lot as consumed
            $parentLot->update(['status' => 'consumed']);

            // INV-06: consuming the parent block is an inventory event and must
            // leave a movement, not just a status change.
            InventoryMovement::create([
                'operating_unit_id' => $parentLot->warehouse?->operating_unit_id,
                'stock_lot_id' => $parentLot->id,
                'from_warehouse_id' => $parentLot->warehouse_id,
                'sku' => $parentLot->inventoryItem?->sku ?? 'FOAM-BLOCK',
                'movement_type' => 'consumption',
                'quantity_delta' => -1,
                'unit_cost' => (float) $parentLot->unit_cost,
                'reason' => 'cutter_consumption',
                'reference_document_type' => 'StockLot',
                'reference_id' => $parentLot->id,
            ]);

            $remnantLot = null;
            $byproductMovement = null;

            if ($remnantAction === 'restock_remnant') {
                if (! $remnantDimensions || ! isset($remnantDimensions['length_m'], $remnantDimensions['width_m'], $remnantDimensions['height_m'])) {
                    throw new \InvalidArgumentException('Remnant dimensions (length, width, height) are required when restocking remnant block.');
                }

                // Per-parent revision counter. The previous implementation appended
                // time(), which collides for two remnants cut in the same second.
                $revision = StockLot::withTrashed()
                    ->where('remnant_of_lot_id', $parentLot->id)
                    ->count() + 1;

                $remnantLot = StockLot::create([
                    'inventory_item_id' => $parentLot->inventory_item_id,
                    'warehouse_id' => $parentLot->warehouse_id,
                    'lot_number' => $parentLot->lot_number.'-R'.$revision,
                    'remnant_of_lot_id' => $parentLot->id,
                    'pressure' => $parentLot->pressure,
                    'quantity' => 1.0,
                    'length_m' => (float) $remnantDimensions['length_m'],
                    'width_m' => (float) $remnantDimensions['width_m'],
                    'height_m' => (float) $remnantDimensions['height_m'],
                    'unit_cost' => $parentLot->unit_cost,
                    'grade' => $parentLot->grade,
                    'status' => 'available',
                    'production_batch_id' => $parentLot->production_batch_id,
                ]);

                // The remnant is new stock, so it enters through a movement too.
                InventoryMovement::create([
                    'operating_unit_id' => $parentLot->warehouse?->operating_unit_id,
                    'stock_lot_id' => $remnantLot->id,
                    'to_warehouse_id' => $remnantLot->warehouse_id,
                    'sku' => $parentLot->inventoryItem?->sku ?? 'FOAM-BLOCK',
                    'movement_type' => 'production_output',
                    'quantity_delta' => 1,
                    'unit_cost' => (float) $remnantLot->unit_cost,
                    'reason' => 'cutter_remnant_restock',
                    'reference_document_type' => 'StockLot',
                    'reference_id' => $parentLot->id,
                ]);
            }

            if ($byproductWeightKg !== null && $byproductWeightKg > 0) {
                $byproductMovement = InventoryMovement::create([
                    'id' => (string) Str::uuid(),
                    'operating_unit_id' => $parentLot->warehouse?->operating_unit_id,
                    'to_warehouse_id' => $parentLot->warehouse_id,
                    'sku' => 'BYPRODUCT-FILL',
                    'movement_type' => 'byproduct_yield',
                    'quantity_delta' => $byproductWeightKg,
                    'reason' => "Cutter byproduct fill from block {$parentLot->lot_number}",
                    'reference_document_type' => 'StockLot',
                    'reference_id' => $parentLot->id,
                ]);
            }

            return [
                'parent_lot' => $parentLot->fresh(['inventoryItem', 'warehouse']),
                'remnant_lot' => $remnantLot ? $remnantLot->fresh(['inventoryItem', 'warehouse']) : null,
                'byproduct_movement' => $byproductMovement,
            ];
        });
    }
}
