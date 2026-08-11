<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\StockLot;
use App\Models\Warehouse;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:warehouses,code'],
            'is_internal_unit' => ['nullable', 'boolean'],
        ]);

        // The trait fills operating_unit_id from context on create, so a
        // company-wide role with no unit selected would leave it null against a
        // NOT NULL column.
        if ($this->unitContext->getUnitId() === null) {
            return response()->json([
                'message' => 'Select an operating unit before creating a warehouse.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $warehouse = Warehouse::create($validated);

        return response()->json($warehouse, 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Warehouse::with('operatingUnit')->findOrFail($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $warehouse = Warehouse::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('warehouses', 'code')->ignore($warehouse->id)],
            'is_internal_unit' => ['nullable', 'boolean'],
        ]);

        $warehouse->update($validated);

        return response()->json($warehouse);
    }

    public function destroy(string $id): JsonResponse
    {
        $warehouse = Warehouse::findOrFail($id);

        // Stock lots are located by warehouse; removing one out from under live
        // stock would orphan it.
        if (StockLot::where('warehouse_id', $warehouse->id)->exists()) {
            return response()->json([
                'message' => 'Cannot delete a warehouse that still holds stock lots.',
                'code' => 'WAREHOUSE_NOT_EMPTY',
            ], 422);
        }

        $warehouse->delete();

        return response()->json(['message' => 'Warehouse deleted.']);
    }
}
