<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\v1\CompleteWorkOrderRequest;
use App\Http\Requests\Api\v1\StoreWorkOrderRequest;
use App\Http\Requests\Api\v1\UpdateWorkOrderRequest;
use App\Models\InventoryMovement;
use App\Models\WorkOrder;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WorkOrderController extends Controller
{
    /**
     * Display a listing of work orders.
     */
    public function index(Request $request): JsonResponse
    {
        $unitId = $request->header('X-Operating-Unit-ID')
            ?? app(CurrentUnitContext::class)->getUnitId();

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
    public function store(StoreWorkOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $unitId = $validated['operating_unit_id']
            ?? $request->header('X-Operating-Unit-ID')
            ?? app(CurrentUnitContext::class)->getUnitId();

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
     * Complete a work order and record raw material consumption & finished product intake.
     */
    public function complete(CompleteWorkOrderRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        $workOrder = DB::transaction(function () use ($validated, $id) {
            $order = WorkOrder::findOrFail($id);
            $order->update(['status' => 'completed']);

            // Record raw material consumption
            InventoryMovement::create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $order->operating_unit_id,
                'sku' => $validated['consumed_sku'],
                'quantity_delta' => -abs((float) $validated['consumed_qty']),
                'reason' => "Raw material consumption for Work Order #{$order->id}",
                'reference_id' => $order->id,
            ]);

            // Record finished product stock addition
            InventoryMovement::create([
                'id' => (string) Str::uuid(),
                'operating_unit_id' => $order->operating_unit_id,
                'sku' => $order->product_sku,
                'quantity_delta' => (float) $order->quantity,
                'reason' => "Production output from Work Order #{$order->id}",
                'reference_id' => $order->id,
            ]);

            return $order;
        });

        return response()->json($workOrder);
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
    public function update(UpdateWorkOrderRequest $request, string $id): JsonResponse
    {
        $workOrder = WorkOrder::findOrFail($id);

        $workOrder->update($request->validated());

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
