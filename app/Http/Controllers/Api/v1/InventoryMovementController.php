<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class InventoryMovementController extends Controller
{
    /**
     * Display a listing of inventory movements.
     */
    public function index(Request $request): JsonResponse
    {
        $unitId = $request->header('X-Operating-Unit-ID')
            ?? app(CurrentUnitContext::class)->getUnitId();

        $query = InventoryMovement::query();

        if ($unitId) {
            $query->where('operating_unit_id', $unitId);
        }

        $movements = $query->latest()->paginate();

        return response()->json($movements);
    }

    /**
     * Store a newly created inventory movement.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operating_unit_id' => ['nullable', 'uuid', 'exists:operating_units,id'],
            'sku' => ['required', 'string', 'max:255'],
            'quantity_delta' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:255'],
            'reference_id' => ['nullable', 'uuid'],
        ]);

        $unitId = $validated['operating_unit_id']
            ?? $request->header('X-Operating-Unit-ID')
            ?? app(CurrentUnitContext::class)->getUnitId();

        if (! $unitId) {
            return response()->json(['message' => 'Operating Unit context header or body field is required.'], 422);
        }

        $movement = InventoryMovement::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unitId,
            'sku' => $validated['sku'],
            'quantity_delta' => $validated['quantity_delta'],
            'reason' => $validated['reason'],
            'reference_id' => $validated['reference_id'] ?? null,
        ]);

        return response()->json($movement, 201);
    }

    /**
     * Get net stock balance for a given SKU.
     */
    public function stock(string $sku, Request $request): JsonResponse
    {
        $unitId = $request->header('X-Operating-Unit-ID')
            ?? app(CurrentUnitContext::class)->getUnitId();

        $query = InventoryMovement::query()->where('sku', $sku);

        if ($unitId) {
            $query->where('operating_unit_id', $unitId);
        }

        $totalStock = (float) $query->sum('quantity_delta');

        return response()->json(['stock' => $totalStock]);
    }

    /**
     * Display the specified inventory movement.
     */
    public function show(string $id): JsonResponse
    {
        $movement = InventoryMovement::findOrFail($id);

        return response()->json($movement);
    }

    /**
     * Remove the specified inventory movement.
     */
    public function destroy(string $id): JsonResponse
    {
        $movement = InventoryMovement::findOrFail($id);
        $movement->delete();

        return response()->json(['message' => 'Inventory movement deleted successfully.']);
    }
}
