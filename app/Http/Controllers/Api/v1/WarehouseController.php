<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\OperatingUnit;
use App\Models\StockLot;
use App\Models\Warehouse;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function __construct(public CurrentUnitContext $unitContext) {}

    /**
     * Warehouses available to the current operating unit.
     *
     * Scoping is handled by the BelongsToOperatingUnit global scope on the model,
     * so no explicit unit filter is applied here.
     */
    public function index(): JsonResponse
    {
        return response()->json(Warehouse::orderBy('name')->get());
    }

    /**
     * Warehouses belonging to a specific operating unit (admin view).
     */
    public function forOperatingUnit(string $operatingUnitId): JsonResponse
    {
        $unit = OperatingUnit::withTrashed()->findOrFail($operatingUnitId);

        $warehouses = Warehouse::withoutGlobalScopes()
            ->where('operating_unit_id', $unit->id)
            ->orderBy('name')
            ->get();

        return response()->json($warehouses);
    }

    /**
     * Create a warehouse directly for a specific operating unit (admin action).
     */
    public function storeForOperatingUnit(Request $request, string $operatingUnitId): JsonResponse
    {
        $unit = OperatingUnit::findOrFail($operatingUnitId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_internal_unit' => ['nullable', 'boolean'],
        ]);

        $warehouse = Warehouse::create([
            'operating_unit_id' => $unit->id,
            'name' => $validated['name'],
            'is_internal_unit' => $validated['is_internal_unit'] ?? false,
        ]);

        return response()->json($warehouse, 201);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'operating_unit_id' => ['nullable', 'uuid', 'exists:operating_units,id'],
            'is_internal_unit' => ['nullable', 'boolean'],
        ]);

        $unitId = $validated['operating_unit_id'] ?? $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before creating a warehouse.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $warehouse = Warehouse::create([
            'operating_unit_id' => $unitId,
            'name' => $validated['name'],
            'is_internal_unit' => $validated['is_internal_unit'] ?? false,
        ]);

        return response()->json($warehouse, 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Warehouse::withoutGlobalScopes()->with('operatingUnit')->findOrFail($id));
    }

    /**
     * What's currently in this warehouse, grouped by item, with a weighted
     * average unit cost across its available lots.
     */
    public function stockSummary(string $id): JsonResponse
    {
        $warehouse = Warehouse::findOrFail($id);

        $rows = StockLot::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_lots.inventory_item_id')
            ->where('stock_lots.warehouse_id', $warehouse->id)
            ->where('stock_lots.status', 'available')
            ->groupBy(
                'stock_lots.inventory_item_id',
                'inventory_items.name',
                'inventory_items.code',
                'inventory_items.unit_of_measure',
            )
            ->selectRaw('stock_lots.inventory_item_id as inventory_item_id')
            ->selectRaw('inventory_items.name as item_name')
            ->selectRaw('inventory_items.code as item_code')
            ->selectRaw('inventory_items.unit_of_measure as uom')
            ->selectRaw('SUM(stock_lots.quantity) as total_quantity')
            ->selectRaw('SUM(stock_lots.quantity * stock_lots.unit_cost) as total_value')
            ->selectRaw('COUNT(*) as lots_count')
            ->orderBy('inventory_items.name')
            ->get()
            ->map(function ($row): array {
                $totalQuantity = (float) $row->total_quantity;
                $totalValue = round((float) $row->total_value, 4);

                return [
                    'inventory_item_id' => $row->inventory_item_id,
                    'item_name' => $row->item_name,
                    'item_code' => $row->item_code,
                    'uom' => $row->uom,
                    'total_quantity' => $totalQuantity,
                    'avg_unit_cost' => $totalQuantity > 0 ? round($totalValue / $totalQuantity, 4) : 0.0,
                    'total_value' => $totalValue,
                    'lots_count' => (int) $row->lots_count,
                ];
            });

        return response()->json([
            'warehouse' => ['id' => $warehouse->id, 'name' => $warehouse->name],
            'rows' => $rows,
            'total_value' => round((float) $rows->sum('total_value'), 4),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $warehouse = Warehouse::withoutGlobalScopes()->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_internal_unit' => ['nullable', 'boolean'],
        ]);

        $warehouse->update($validated);

        return response()->json($warehouse);
    }

    public function destroy(string $id): JsonResponse
    {
        $warehouse = Warehouse::withoutGlobalScopes()->findOrFail($id);

        // Stock lots are located by warehouse; removing one out from under live
        // stock would orphan it.
        if (StockLot::where('warehouse_id', $warehouse->id)->exists()) {
            return response()->json([
                'message' => 'Cannot delete a warehouse that still holds stock lots.',
                'code' => 'WAREHOUSE_NOT_EMPTY',
            ], 422);
        }

        // An operating unit must retain at least one warehouse for operational workflows.
        $totalWarehouses = Warehouse::withoutGlobalScopes()
            ->where('operating_unit_id', $warehouse->operating_unit_id)
            ->count();

        if ($totalWarehouses <= 1) {
            return response()->json([
                'message' => 'Cannot delete the only warehouse of an operating unit.',
                'code' => 'CANNOT_DELETE_LAST_WAREHOUSE',
            ], 422);
        }

        $warehouse->delete();

        return response()->json(['message' => 'Warehouse deleted.']);
    }
}
