<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\StockAdjustmentRequest;
use App\Services\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAdjustmentRequestController extends Controller
{
    public function __construct(public StockAdjustmentService $adjustmentService) {}

    public function index(Request $request): JsonResponse
    {
        $query = StockAdjustmentRequest::with(['operatingUnit', 'stockLot.inventoryItem', 'requestedBy', 'approvedBy']);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operating_unit_id' => ['required', 'uuid', 'exists:operating_units,id'],
            'stock_lot_id' => ['required', 'uuid', 'exists:stock_lots,id'],
            'reason_code' => ['required', 'string', 'in:audit_reconciliation,spill_loss,damage,expired'],
            'quantity_delta' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $adjRequest = $this->adjustmentService->createRequest(
            $validated['operating_unit_id'],
            $validated['stock_lot_id'],
            $validated['reason_code'],
            (float) $validated['quantity_delta'],
            $validated['notes'] ?? null,
            $request->user()
        );

        return response()->json($adjRequest->load(['operatingUnit', 'stockLot', 'requestedBy']), 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $adjRequest = StockAdjustmentRequest::findOrFail($id);
        $approved = $this->adjustmentService->approve($adjRequest, $request->user());

        return response()->json($approved->load(['operatingUnit', 'stockLot', 'approvedBy']));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $adjRequest = StockAdjustmentRequest::findOrFail($id);
        $rejected = $this->adjustmentService->reject($adjRequest, $request->user());

        return response()->json($rejected->load(['operatingUnit', 'stockLot', 'approvedBy']));
    }
}
