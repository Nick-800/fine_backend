<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosController extends Controller
{
    public function __construct(
        public SalesOrderService $salesOrderService,
        public CurrentUnitContext $unitContext,
    ) {}

    /**
     * One call, one sale: goods out, money in, receipt back (SALE-09).
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:100', 'unique:sales_orders,order_number'],
            'payment_method' => ['required', Rule::in(['cash', 'card'])],
            'client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before selling.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $order = $this->salesOrderService->posCheckout(
            $unitId,
            $validated['items'],
            $validated['payment_method'],
            $validated['order_number'],
            $validated['client_id'] ?? null,
        );

        return response()->json($order, 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            SalesOrder::with(['lines.inventoryItem'])
                ->where('channel', 'pos')
                ->findOrFail($id)
        );
    }

    /**
     * Cash-drawer reconciliation: today's POS takings by payment method.
     */
    public function dailyReport(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        $sales = SalesOrder::where('channel', 'pos')
            ->whereDate('created_at', $date)
            ->get();

        return response()->json([
            'date' => $date,
            'sales_count' => $sales->count(),
            'total' => round((float) $sales->sum('total_amount'), 4),
            'by_method' => $sales->groupBy('payment_method')->map(fn ($group) => [
                'count' => $group->count(),
                'total' => round((float) $group->sum('total_amount'), 4),
            ]),
            'total_cost' => round((float) $sales->sum('total_cost'), 4),
        ]);
    }
}
