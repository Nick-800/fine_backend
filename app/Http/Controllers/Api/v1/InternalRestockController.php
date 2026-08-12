<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\InternalRestockRequest;
use App\Services\SalesOrderService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalRestockController extends Controller
{
    public function __construct(
        public SalesOrderService $salesOrderService,
        public CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = InternalRestockRequest::with(['requestingUnit', 'sourceUnit', 'lines.inventoryItem'])
            ->latest();

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        // A unit sees traffic in both directions: what it asked for and what is
        // being asked of it.
        if ($unitId = $this->unitContext->getUnitId()) {
            $query->where(function ($q) use ($unitId): void {
                $q->where('requesting_unit_id', $unitId)->orWhere('source_unit_id', $unitId);
            });
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_number' => ['required', 'string', 'max:100', 'unique:internal_restock_requests,request_number'],
            'source_unit_id' => ['required', 'uuid', 'exists:operating_units,id'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before requesting stock.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        if ($validated['source_unit_id'] === $unitId) {
            return response()->json([
                'message' => 'A unit cannot restock from itself.',
                'code' => 'SELF_TRANSFER',
            ], 422);
        }

        $restock = InternalRestockRequest::create([
            'request_number' => $validated['request_number'],
            'requesting_unit_id' => $unitId,
            'source_unit_id' => $validated['source_unit_id'],
            'notes' => $validated['notes'] ?? null,
        ]);

        foreach ($validated['lines'] as $line) {
            $restock->lines()->create($line);
        }

        return response()->json($restock->fresh(['lines.inventoryItem', 'sourceUnit']), 201);
    }

    /** SALE-05: the source unit's manager says yes. */
    public function approve(Request $request, string $id): JsonResponse
    {
        $restock = InternalRestockRequest::findOrFail($id);

        if ($restock->status !== 'pending_approval') {
            return response()->json([
                'message' => "Request {$restock->request_number} is {$restock->status} and cannot be approved.",
                'code' => 'INVALID_STATE_TRANSITION',
            ], 422);
        }

        $restock->update([
            'status' => 'approved',
            'decided_by_user_id' => $request->user()?->id,
            'decided_at' => now(),
        ]);

        return response()->json($restock->fresh(['lines']));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $restock = InternalRestockRequest::findOrFail($id);

        if ($restock->status !== 'pending_approval') {
            return response()->json([
                'message' => "Request {$restock->request_number} is {$restock->status} and cannot be rejected.",
                'code' => 'INVALID_STATE_TRANSITION',
            ], 422);
        }

        $restock->update([
            'status' => 'rejected',
            'decided_by_user_id' => $request->user()?->id,
            'decided_at' => now(),
        ]);

        return response()->json($restock->fresh());
    }

    public function fulfill(string $id): JsonResponse
    {
        $restock = InternalRestockRequest::findOrFail($id);

        return response()->json($this->salesOrderService->fulfillRestock($restock));
    }
}
