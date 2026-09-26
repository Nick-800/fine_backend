<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\PosDailyClose;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

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
            'items.*.stock_lot_id' => ['sometimes', 'nullable', 'uuid', 'exists:stock_lots,id'],
            'items.*.bundle_id' => ['sometimes', 'nullable', 'uuid', 'exists:bundles,id'],
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

        try {
            $order = $this->salesOrderService->posCheckout(
                $unitId,
                $validated['items'],
                $validated['payment_method'],
                $validated['order_number'],
                $validated['client_id'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'POS_LOT_REJECTED',
            ], 422);
        }

        return response()->json($order, 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            SalesOrder::with(['lines.inventoryItem', 'lines.stockLot', 'lines.bundle'])
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

    /**
     * The recorded register close for a date, if the drawer was counted.
     */
    public function showDailyClose(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        return response()->json([
            'data' => PosDailyClose::with('closedBy')->whereDate('close_date', $date)->first(),
        ]);
    }

    /**
     * Persist the Z-report drawer count. Expected cash is derived server-side
     * from the same sales the drawer report shows — the client only supplies
     * what was physically counted.
     */
    public function dailyClose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before closing the register.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $date = $validated['date'] ?? now()->toDateString();

        if (PosDailyClose::whereDate('close_date', $date)->exists()) {
            return response()->json([
                'message' => 'This register day is already closed.',
                'code' => 'POS_DAY_ALREADY_CLOSED',
            ], 422);
        }

        $sales = SalesOrder::where('channel', 'pos')
            ->whereDate('created_at', $date)
            ->get();

        $expected = round((float) $sales->where('payment_method', 'cash')->sum('total_amount'), 4);
        $counted = round((float) $validated['counted_cash'], 4);

        $close = PosDailyClose::create([
            'operating_unit_id' => $unitId,
            'close_date' => $date,
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'difference' => round($counted - $expected, 4),
            'sales_count' => $sales->count(),
            'total_sales' => round((float) $sales->sum('total_amount'), 4),
            'notes' => $validated['notes'] ?? null,
            'closed_by_user_id' => $request->user()->id,
        ]);

        return response()->json($close->load('closedBy'), 201);
    }
}
