<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductionOrderStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Bom;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Models\LaborLog;
use App\Models\LaborRoleRate;
use App\Models\ProductionOrder;
use App\Models\StockLot;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProductionOrderService
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly MaterialResolutionService $materialResolution,
    ) {}

    public function transition(ProductionOrder $order, ProductionOrderStatus $target): ProductionOrder
    {
        return DB::transaction(function () use ($order, $target): ProductionOrder {
            $locked = ProductionOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->status;

            if (! $current->canTransitionTo($target)) {
                $allowed = array_map(fn (ProductionOrderStatus $s) => $s->value, $current->allowedNext());

                throw new InvalidStateTransitionException(
                    $allowed === []
                        ? "Order {$locked->order_number} is completed and cannot change state."
                        : "Cannot move order {$locked->order_number} from {$current->value} to {$target->value}; expected ".implode(' or ', $allowed).'.'
                );
            }

            $this->guardTransition($locked, $target);

            $locked->status = $target;
            $locked->save();

            match ($target) {
                ProductionOrderStatus::InProduction => $this->materialResolution->resolveForProductionOrder($locked),
                ProductionOrderStatus::QualityCheck => $this->consumeReservations($locked),
                ProductionOrderStatus::ReadyForCollection => $this->createFinishedGood($locked),
                ProductionOrderStatus::Completed => $this->collect($locked),
                default => null,
            };

            return $locked->fresh(['product', 'bom', 'laborLogs']);
        });
    }

    private function guardTransition(ProductionOrder $order, ProductionOrderStatus $target): void
    {
        // A BOM with nothing in it cannot be confirmed — there is no build to do.
        if ($target === ProductionOrderStatus::BomConfirmed) {
            $bom = $order->bom()->withCount('componentLines')->first();

            if ($bom === null || $bom->component_lines_count === 0) {
                throw new InvalidStateTransitionException(
                    "Order {$order->order_number} has no BOM components to build from."
                );
            }
        }

        // The order can't move into assembly until every material request
        // posted at resolution time is fulfilled (cutter ran, foam poured,
        // procurement arrived). The resolver manages the counter.
        if ($target === ProductionOrderStatus::InProduction
            && (int) $order->awaiting_material_requests_count > 0) {
            throw new InvalidStateTransitionException(
                "Order {$order->order_number} has {$order->awaiting_material_requests_count} open material request(s); resolve them first."
            );
        }

        // FUR-07: nothing was ever reserved means nothing was built.
        if ($target === ProductionOrderStatus::QualityCheck
            && ! InventoryMovement::where('reference_document_type', 'ProductionOrder')
                ->where('reference_id', $order->id)
                ->where('movement_type', 'reservation')
                ->exists()) {
            throw new InvalidStateTransitionException(
                "Order {$order->order_number} has no reserved components; production never started."
            );
        }
    }

    /**
     * Turn the holds into real consumption and cost the order from what was
     * actually taken — never from BOM estimates.
     */
    private function consumeReservations(ProductionOrder $order): void
    {
        $reservations = InventoryMovement::where('reference_document_type', 'ProductionOrder')
            ->where('reference_id', $order->id)
            ->where('movement_type', 'reservation')
            ->get();

        $materialCost = 0.0;
        $creditByAccount = [];

        foreach ($reservations as $reservation) {
            $lot = StockLot::withoutGlobalScopes()->whereKey($reservation->stock_lot_id)->lockForUpdate()->first();

            if ($lot === null) {
                continue;
            }

            $take = round(abs((float) $reservation->quantity_delta), 4);
            $lineCost = round($take * (float) $reservation->unit_cost, 4);
            $materialCost += $lineCost;

            // A bulk lot only partly needed gives its surplus back to stock.
            if ((float) $lot->quantity > $take) {
                $lot->quantity = round((float) $lot->quantity - $take, 4);
                $lot->status = 'available';
            } else {
                $lot->quantity = 0;
                $lot->status = 'consumed';
            }

            $lot->save();

            InventoryMovement::create([
                'operating_unit_id' => $order->operating_unit_id,
                'stock_lot_id' => $lot->id,
                'from_warehouse_id' => $lot->warehouse_id,
                'sku' => $reservation->sku,
                'movement_type' => 'consumption',
                'quantity_delta' => -$take,
                'unit_cost' => (float) $reservation->unit_cost,
                'reason' => 'production_order_consumption',
                'reference_document_type' => 'ProductionOrder',
                'reference_id' => $order->id,
            ]);

            // Cutter outputs sit in finished goods; everything else is credited
            // out of raw materials.
            $itemType = $lot->inventoryItem?->item_type;
            $account = in_array($itemType, ['cut_template_piece', 'slice'], true) ? '1132' : '111';
            $creditByAccount[$account] = round(($creditByAccount[$account] ?? 0) + $lineCost, 4);
        }

        $order->material_cost = round($materialCost, 4);
        $order->save();

        if ($materialCost <= 0) {
            return;
        }

        $journalLines = [[
            'account_code' => '1123', // WIP — Furniture Production
            'debit' => round($materialCost, 4),
            'operating_unit_id' => $order->operating_unit_id,
        ]];

        foreach ($creditByAccount as $account => $amount) {
            $journalLines[] = [
                'account_code' => (string) $account,
                'credit' => $amount,
                'operating_unit_id' => $order->operating_unit_id,
            ];
        }

        $this->accountingService->postJournal(
            "Components consumed for furniture order {$order->order_number}",
            $journalLines,
            'ProductionOrder',
            $order->id,
            $order->operatingUnit?->company_id,
        );
    }

    /**
     * FUR-08: the built product enters stock at material + labor.
     */
    private function createFinishedGood(ProductionOrder $order): void
    {
        $laborCost = round((float) $order->laborLogs()->get()->sum(fn (LaborLog $l) => $l->cost()), 4);
        $order->labor_cost = $laborCost;

        $materialCost = (float) $order->material_cost;
        $total = round($materialCost + $laborCost, 4);

        // Land the finished good where the components came from.
        $warehouseId = InventoryMovement::where('reference_document_type', 'ProductionOrder')
            ->where('reference_id', $order->id)
            ->where('movement_type', 'consumption')
            ->value('from_warehouse_id');

        $product = $order->product;

        $lot = StockLot::create([
            'inventory_item_id' => $product->inventory_item_id,
            'warehouse_id' => $warehouseId,
            'lot_number' => 'FG-'.$order->order_number,
            'quantity' => $order->quantity,
            'unit_cost' => $order->quantity > 0 ? round($total / $order->quantity, 4) : 0,
            'status' => 'available',
        ]);

        $order->finished_stock_lot_id = $lot->id;
        $order->save();

        InventoryMovement::create([
            'operating_unit_id' => $order->operating_unit_id,
            'stock_lot_id' => $lot->id,
            'to_warehouse_id' => $warehouseId,
            'sku' => $product->inventoryItem?->sku ?? $product->sku,
            'movement_type' => 'production_output',
            'quantity_delta' => $order->quantity,
            'unit_cost' => (float) $lot->unit_cost,
            'reason' => 'furniture_assembled',
            'reference_document_type' => 'ProductionOrder',
            'reference_id' => $order->id,
        ]);

        if ($total <= 0) {
            return;
        }

        $lines = [[
            'account_code' => '1134', // Finished Goods — Furniture
            'debit' => $total,
            'operating_unit_id' => $order->operating_unit_id,
        ]];

        if ($materialCost > 0) {
            $lines[] = ['account_code' => '1123', 'credit' => $materialCost, 'operating_unit_id' => $order->operating_unit_id];
        }

        if ($laborCost > 0) {
            // Wages Payable: the workshop is owed for the hours built in.
            $lines[] = ['account_code' => '22', 'credit' => $laborCost, 'operating_unit_id' => $order->operating_unit_id];
        }

        $this->accountingService->postJournal(
            "Furniture order {$order->order_number} finished",
            $lines,
            'ProductionOrder',
            $order->id,
            $order->operatingUnit?->company_id,
        );
    }

    /**
     * Collection: the piece physically leaves. COGS is recognised now so the
     * inventory and the ledger stay in step; the revenue side arrives with the
     * Sales module (Phase 07).
     */
    private function collect(ProductionOrder $order): void
    {
        $lot = $order->finishedStockLot;

        if ($lot === null) {
            return;
        }

        $value = round((float) $lot->unit_cost * (float) $lot->quantity, 4);

        $lot->status = 'consumed';
        $lot->quantity = 0;
        $lot->save();

        InventoryMovement::create([
            'operating_unit_id' => $order->operating_unit_id,
            'stock_lot_id' => $lot->id,
            'from_warehouse_id' => $lot->warehouse_id,
            'sku' => $order->product?->inventoryItem?->sku ?? 'FURNITURE',
            'movement_type' => 'issue',
            'quantity_delta' => -$order->quantity,
            'unit_cost' => (float) $lot->unit_cost,
            'reason' => 'collected',
            'reference_document_type' => 'ProductionOrder',
            'reference_id' => $order->id,
        ]);

        if ($value <= 0) {
            return;
        }

        $this->accountingService->postJournal(
            "Furniture order {$order->order_number} collected",
            [
                ['account_code' => '51', 'debit' => $value, 'operating_unit_id' => $order->operating_unit_id],
                ['account_code' => '1134', 'credit' => $value, 'operating_unit_id' => $order->operating_unit_id],
            ],
            'ProductionOrder',
            $order->id,
            $order->operatingUnit?->company_id,
        );
    }

    /**
     * FUR-06: the rate is fixed at the moment of logging. It defaults from the
     * BOM's matching labor requirement so workshop staff enter only hours.
     */
    public function logLabor(
        ProductionOrder $order,
        Employee $employee,
        string $role,
        float $hours,
        ?float $hourlyRate = null,
    ): LaborLog {
        if ($hours <= 0) {
            throw new InvalidArgumentException('Logged hours must be greater than zero.');
        }

        if (! $order->status->acceptsLaborLogs()) {
            throw new InvalidStateTransitionException(
                "Labor cannot be logged while order {$order->order_number} is {$order->status->value}."
            );
        }

        if ($hourlyRate === null) {
            // Phase 09: versioned role rates are the source of truth; the
            // BOM's requirement rate remains as fallback for roles that have
            // no rate history yet. The snapshot below makes the swap safe.
            $hourlyRate = LaborRoleRate::rateFor($role)
                ?? (float) ($order->bom->laborRequirements()
                    ->where('role', $role)
                    ->value('hourly_rate') ?? 0.0);

            if ($hourlyRate <= 0) {
                throw new InvalidArgumentException(
                    "No rate found for role {$role} on this BOM; provide hourly_rate explicitly."
                );
            }
        }

        return LaborLog::create([
            'production_order_id' => $order->id,
            'employee_id' => $employee->id,
            'role' => $role,
            'hours_logged' => round($hours, 2),
            'hourly_rate_at_log' => round($hourlyRate, 4),
            'logged_at' => now(),
        ]);
    }

    /**
     * FUR-03: a custom order clones the closest BOM and adapts the copy, leaving
     * the original untouched and a trail back to it.
     */
    public function cloneBom(Bom $source): Bom
    {
        return DB::transaction(function () use ($source): Bom {
            $nextVersion = ((int) Bom::withTrashed()
                ->where('product_id', $source->product_id)
                ->max('version')) + 1;

            $clone = Bom::create([
                'product_id' => $source->product_id,
                'version' => $nextVersion,
                'is_active' => false,
                'cloned_from_bom_id' => $source->id,
                'notes' => "Cloned from v{$source->version}",
            ]);

            foreach ($source->componentLines as $line) {
                $clone->componentLines()->create($line->only([
                    'inventory_item_id', 'quantity', 'estimated_unit_cost',
                ]));
            }

            foreach ($source->laborRequirements as $req) {
                $clone->laborRequirements()->create($req->only([
                    'role', 'estimated_hours', 'hourly_rate',
                ]));
            }

            return $clone->fresh(['componentLines', 'laborRequirements']);
        });
    }

    /**
     * FUR-01: one active BOM per product at a time.
     */
    public function activateBom(Bom $bom): Bom
    {
        return DB::transaction(function () use ($bom): Bom {
            Bom::where('product_id', $bom->product_id)
                ->whereKeyNot($bom->id)
                ->update(['is_active' => false]);

            $bom->is_active = true;
            $bom->save();

            return $bom->fresh();
        });
    }
}
