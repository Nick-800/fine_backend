<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BomComponentLine;
use App\Models\CutterWorkOrder;
use App\Models\ImportOrder;
use App\Models\InventoryMovement;
use App\Models\MaterialRequest;
use App\Models\Model;
use App\Models\ProductionBatch;
use App\Models\ProductionOrder;
use App\Models\StockLot;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MaterialResolutionService
{
    /**
     * Resolve every component of a production order's BOM:
     *   - if matching stock is on hand, reserve it;
     *   - otherwise create a material_request addressed to the right module.
     *
     * The order's awaiting_material_requests_count reflects the number of
     * open requests (pending + in_progress) so the UI can show a progress
     * indicator and the transition to in_production can be gated.
     */
    public function resolveForProductionOrder(ProductionOrder $order): ProductionOrder
    {
        return DB::transaction(function () use ($order) {
            $order = ProductionOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $lines = $order->bom->componentLines()->with('inventoryItem')->get();

            $openRequestDelta = 0;
            $reservedLots = [];

            foreach ($lines as $line) {
                $needed = round((float) $line->quantity * $order->quantity, 4);

                $picked = $this->pickStockFor($line, $needed);

                if ($picked['shortfall'] <= 0) {
                    $reservedLots = array_merge($reservedLots, $picked['picks']);

                    continue;
                }

                // Couldn't cover the line — file a request and let the
                // relevant module handle it.
                $module = $this->moduleFor($line);

                MaterialRequest::create([
                    'fulfilling_module' => $module,
                    'inventory_item_id' => $line->inventory_item_id,
                    'quantity' => $needed,
                    'target_dimensions' => $this->targetDimensionsFor($line),
                    'status' => MaterialRequest::STATUS_PENDING,
                    'requested_for_type' => 'production_order',
                    'requested_for_id' => $order->id,
                    'operating_unit_id' => $order->operating_unit_id,
                ]);

                $openRequestDelta++;
            }

            foreach ($reservedLots as $pick) {
                $lot = StockLot::whereKey($pick['lot_id'])->lockForUpdate()->first();
                $lot->status = 'reserved';
                $lot->save();

                InventoryMovement::create([
                    'operating_unit_id' => $order->operating_unit_id,
                    'stock_lot_id' => $lot->id,
                    'from_warehouse_id' => $lot->warehouse_id,
                    'sku' => $lot->inventoryItem?->sku ?? 'COMPONENT',
                    'movement_type' => 'reservation',
                    'quantity_delta' => -$pick['take'],
                    'unit_cost' => (float) $lot->unit_cost,
                    'reason' => 'production_order_reservation',
                    'reference_document_type' => 'ProductionOrder',
                    'reference_id' => $order->id,
                ]);
            }

            if ($openRequestDelta > 0) {
                $order->awaiting_material_requests_count = (int) $order->awaiting_material_requests_count + $openRequestDelta;
                $order->save();
            }

            return $order->refresh();
        });
    }

    /**
     * Mark the request in_progress — typically called when the fulfilling
     * module picks it up.
     */
    public function markInProgress(MaterialRequest $request): MaterialRequest
    {
        if ($request->status !== MaterialRequest::STATUS_PENDING) {
            throw new InvalidArgumentException(
                "Material request {$request->id} is {$request->status}; only pending requests can be started."
            );
        }

        $request->status = MaterialRequest::STATUS_IN_PROGRESS;
        $request->save();

        return $request;
    }

    /**
     * Mark the request fulfilled by the given model. Decrements the linked
     * production order's awaiting counter when the counter drops to zero.
     */
    public function markFulfilled(MaterialRequest $request, EloquentModel $fulfillingModel): MaterialRequest
    {
        return DB::transaction(function () use ($request, $fulfillingModel) {
            if ($request->status === MaterialRequest::STATUS_FULFILLED) {
                return $request;
            }

            if (! in_array($request->status, [MaterialRequest::STATUS_PENDING, MaterialRequest::STATUS_IN_PROGRESS], true)) {
                throw new InvalidArgumentException(
                    "Material request {$request->id} is {$request->status}; cannot fulfill."
                );
            }

            $request->status = MaterialRequest::STATUS_FULFILLED;
            $request->fulfilled_by_type = $this->mapFulfilledByType($fulfillingModel);
            $request->fulfilled_by_id = $fulfillingModel->getKey();
            $request->fulfilled_at = now();
            $request->save();

            $this->decrementOrderCounter($request);

            return $request->refresh();
        });
    }

    /**
     * Cancel an open request. Decrements the linked production order's
     * awaiting counter.
     */
    public function cancel(MaterialRequest $request): MaterialRequest
    {
        if (! $request->isOpen()) {
            throw new InvalidArgumentException(
                "Material request {$request->id} is {$request->status}; only open requests can be cancelled."
            );
        }

        return DB::transaction(function () use ($request) {
            $request->status = MaterialRequest::STATUS_CANCELLED;
            $request->save();

            $this->decrementOrderCounter($request);

            return $request->refresh();
        });
    }

    /**
     * Map a fulfilling model class to the stable snake_case token we store
     * on the material_request. Covers the three known models and falls
     * back to snake_case for any future additions.
     */
    private function mapFulfilledByType(EloquentModel $model): string
    {
        return match ($model::class) {
            CutterWorkOrder::class => 'cutter_work_order',
            ProductionBatch::class => 'production_batch',
            ProductionOrder::class => 'production_order',
            ImportOrder::class => 'procurement_request',
            default => strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', class_basename($model)) ?? ''),
        };
    }

    /**
     * Find the link back to the production order (if any) and decrement the
     * awaiting counter. Idempotent.
     */
    private function decrementOrderCounter(MaterialRequest $request): void
    {
        if ($request->requested_for_type !== 'production_order' || $request->requested_for_id === null) {
            return;
        }

        ProductionOrder::whereKey($request->requested_for_id)
            ->where('awaiting_material_requests_count', '>', 0)
            ->update([
                'awaiting_material_requests_count' => DB::raw('awaiting_material_requests_count - 1'),
            ]);
    }

    /**
     * Pick available lots for this line. For dimensional items (foam blocks
     * with target_length/width/height set) the match is EXACT — no tolerance.
     */
    private function pickStockFor(BomComponentLine $line, float $needed): array
    {
        $query = StockLot::where('inventory_item_id', $line->inventory_item_id)
            ->where('status', 'available')
            ->orderBy('created_at');

        if ($this->hasTargetDimensions($line)) {
            $query->where(function ($q) use ($line) {
                if ($line->target_length_m !== null) {
                    $q->where('length_m', (float) $line->target_length_m);
                }
                if ($line->target_width_m !== null) {
                    $q->where('width_m', (float) $line->target_width_m);
                }
                if ($line->target_height_m !== null) {
                    $q->where('height_m', (float) $line->target_height_m);
                }
            });
        }

        $lots = $query->get();

        $remaining = $needed;
        $picks = [];

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $lot->quantity, $remaining);
            $picks[] = ['lot_id' => $lot->id, 'take' => round($take, 4)];
            $remaining = round($remaining - $take, 4);
        }

        return [
            'picks' => $picks,
            'shortfall' => $remaining,
        ];
    }

    private function hasTargetDimensions(BomComponentLine $line): bool
    {
        return $line->target_length_m !== null
            || $line->target_width_m !== null
            || $line->target_height_m !== null;
    }

    private function targetDimensionsFor(BomComponentLine $line): ?array
    {
        if (! $this->hasTargetDimensions($line)) {
            return null;
        }

        return array_filter([
            'length_m' => $line->target_length_m,
            'width_m' => $line->target_width_m,
            'height_m' => $line->target_height_m,
        ], fn ($v) => $v !== null);
    }

    /**
     * Which downstream module handles a shortage of this line. Foam blocks
     * go to the cutter (which can escalate to foam). Everything else goes to
     * procurement (out of scope for this round, but the module is registered).
     */
    private function moduleFor(BomComponentLine $line): string
    {
        return $line->inventoryItem?->item_type === 'foam_block'
            ? MaterialRequest::MODULE_CUTTER
            : MaterialRequest::MODULE_PROCUREMENT;
    }
}
