<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\TankStock;
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
