<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\StockLot;
use App\Models\TankStock;
use App\Rules\ExistsInCurrentUnit;
use App\Services\TankStockService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TankStockController extends Controller
{
    public function __construct(
        public TankStockService $tankStockService,
        public CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = TankStock::with(['chemicalItem', 'operatingUnit']);

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        return response()->json($query->get());
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            TankStock::with(['chemicalItem', 'operatingUnit'])->findOrFail($id)
        );
    }

    /**
     * Pour a source lot into the tank — the balanced refill.
     *
     * Cost is taken from the lot, not the request, so it cannot disagree with
     * what was actually paid for the material.
     */
    public function refillFromLot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_stock_lot_id' => ['required', 'uuid', new ExistsInCurrentUnit(StockLot::class, 'stock lot')],
            'draw_quantity' => ['required_without:draw_containers', 'nullable', 'numeric', 'gt:0'],
            'draw_containers' => ['required_without:draw_quantity', 'nullable', 'integer', 'min:1'],
            'reference_id' => ['nullable', 'uuid'],
        ]);

        $lot = StockLot::with(['inventoryItem', 'warehouse'])->findOrFail($validated['source_stock_lot_id']);

        $tank = $this->tankStockService->refillFromLot(
            $lot,
            isset($validated['draw_quantity']) ? (float) $validated['draw_quantity'] : null,
            isset($validated['draw_containers']) ? (int) $validated['draw_containers'] : null,
            $validated['reference_id'] ?? null,
        );

        return response()->json([
            'tank' => $tank->load(['chemicalItem', 'operatingUnit']),
            'source_lot' => $lot->fresh(['inventoryItem', 'warehouse']),
        ], 201);
    }

    public function refill(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chemical_inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'refill_quantity' => ['required', 'numeric', 'gt:0'],
            'refill_unit_cost' => ['required', 'numeric', 'gte:0'],
            'reference_id' => ['nullable', 'uuid'],
        ]);

        // Context, not body: a body value would let a unit-scoped user refill
        // another unit's tank.
        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before refilling a tank.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $tank = $this->tankStockService->refill(
            $validated['chemical_inventory_item_id'],
            $unitId,
            (float) $validated['refill_quantity'],
            (float) $validated['refill_unit_cost'],
            $validated['reference_id'] ?? null
        );

        return response()->json($tank->load(['chemicalItem', 'operatingUnit']), 201);
    }
}
