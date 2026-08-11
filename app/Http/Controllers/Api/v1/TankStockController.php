<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\TankStock;
use App\Services\TankStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TankStockController extends Controller
{
    public function __construct(public TankStockService $tankStockService) {}

    public function index(Request $request): JsonResponse
    {
        $query = TankStock::with(['chemicalItem', 'operatingUnit']);

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        return response()->json($query->get());
    }

    public function refill(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chemical_inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'operating_unit_id' => ['required', 'uuid', 'exists:operating_units,id'],
            'refill_quantity' => ['required', 'numeric', 'gt:0'],
            'refill_unit_cost' => ['required', 'numeric', 'gte:0'],
            'reference_id' => ['nullable', 'uuid'],
        ]);

        $tank = $this->tankStockService->refill(
            $validated['chemical_inventory_item_id'],
            $validated['operating_unit_id'],
            (float) $validated['refill_quantity'],
            (float) $validated['refill_unit_cost'],
            $validated['reference_id'] ?? null
        );

        return response()->json($tank->load(['chemicalItem', 'operatingUnit']), 201);
    }
}
