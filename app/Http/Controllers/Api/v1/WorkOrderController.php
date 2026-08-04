<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class WorkOrderController extends Controller
{
    /**
     * Display a listing of work orders.
     */
    public function index(Request $request): JsonResponse
    {
        $unitId = $request->header('X-Operating-Unit-ID');

        $query = WorkOrder::query();

        if ($unitId) {
            $query->where('operating_unit_id', $unitId);
        }

        $workOrders = $query->latest()->paginate();

        return response()->json($workOrders);
    }

    /**
     * Store a newly created work order.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operating_unit_id' => ['nullable', 'uuid', 'exists:operating_units,id'],
            'product_sku' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'status' => ['required', 'string', 'max:50'],
        ]);

        $unitId = $validated['operating_unit_id'] ?? $request->header('X-Operating-Unit-ID');

        if (! $unitId) {
            return response()->json(['message' => 'Operating Unit context header or body field is required.'], 422);
        }

        $workOrder = WorkOrder::create([
            'id' => (string) Str::uuid(),
            'operating_unit_id' => $unitId,
            'product_sku' => $validated['product_sku'],
            'quantity' => $validated['quantity'],
            'status' => $validated['status'],
        ]);

        return response()->json($workOrder, 201);
    }

    /**
     * Display the specified work order.
     */
    public function show(string $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);

        return response()->json($workOrder);
    }

    /**
     * Update the specified work order.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);

        $validated = $request->validate([
            'product_sku' => ['sometimes', 'string', 'max:255'],
            'quantity' => ['sometimes', 'numeric', 'min:0.0001'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]);

        $workOrder->update($validated);

        return response()->json($workOrder);
    }

    /**
     * Remove the specified work order.
     */
    public function destroy(string $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);
        $workOrder->delete();

        return response()->json(['message' => 'Work order deleted successfully.']);
    }
}
