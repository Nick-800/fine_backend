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
use InvalidArgumentException;

class SalesOrderController extends Controller
{
    public function __construct(
        public SalesOrderService $salesOrderService,
        public CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SalesOrder::with(['client.entity', 'buyerUnit'])->withCount('lines')->latest();

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        if (filled($request->query('channel'))) {
            $query->where('channel', $request->query('channel'));
        }

        if (filled($request->query('buyer_type'))) {
            $query->where('buyer_type', $request->query('buyer_type'));
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => ['required', 'string', 'max:100', 'unique:sales_orders,order_number'],
            'buyer_type' => ['required', Rule::in(['client', 'internal_unit'])],
            'client_id' => ['required_if:buyer_type,client', 'nullable', 'uuid', 'exists:clients,id'],
            'buyer_unit_id' => ['required_if:buyer_type,internal_unit', 'nullable', 'uuid', 'exists:operating_units,id'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'lines.*.stock_lot_id' => ['sometimes', 'nullable', 'uuid', 'exists:stock_lots,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before creating a sales order.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        if (($validated['buyer_unit_id'] ?? null) === $unitId) {
            return response()->json([
                'message' => 'A unit cannot sell to itself.',
                'code' => 'SELF_TRANSFER',
            ], 422);
        }

        $order = SalesOrder::create([
            'operating_unit_id' => $unitId,
            'order_number' => $validated['order_number'],
            'buyer_type' => $validated['buyer_type'],
            'client_id' => $validated['client_id'] ?? null,
            'buyer_unit_id' => $validated['buyer_unit_id'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        foreach ($validated['lines'] as $line) {
            $order->lines()->create($line);
        }

        return response()->json($order->fresh(['lines.inventoryItem', 'client.entity', 'buyerUnit']), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            SalesOrder::with([
                'lines.inventoryItem',
                'lines.stockLot',
                'client.entity',
                'buyerUnit',
                'creditApprovalRequest.decidedBy',
            ])->findOrFail($id)
        );
    }

    /** Draft → confirmed or pending_approval; the credit check decides. */
    public function submit(string $id): JsonResponse
    {
        $order = SalesOrder::findOrFail($id);

        return response()->json(
            $this->salesOrderService->submit($order)->load(['lines', 'creditApprovalRequest'])
        );
    }

    public function fulfill(string $id): JsonResponse
    {
        $order = SalesOrder::findOrFail($id);

        try {
            $result = $this->salesOrderService->fulfill($order);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'LOT_REJECTED',
            ], 422);
        }

        return response()->json($result);
    }

    public function recordPayment(Request $request, string $id): JsonResponse
    {
        $order = SalesOrder::findOrFail($id);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card'])],
        ]);

        return response()->json(
            $this->salesOrderService->recordPayment(
                $order,
                (float) $validated['amount'],
                $validated['payment_method'] ?? null,
            )
        );
    }

    public function complete(string $id): JsonResponse
    {
        $order = SalesOrder::findOrFail($id);

        return response()->json($this->salesOrderService->completeInternal($order));
    }

    /**
     * Structured invoice data (SALE-10). PDF rendering is a front-end/print
     * concern for now; the figures here are the invoice.
     */
    public function invoice(string $id): JsonResponse
    {
        $order = SalesOrder::with(['lines.inventoryItem', 'lines.stockLot', 'client.entity', 'operatingUnit'])->findOrFail($id);

        if (! in_array($order->status->value, ['fulfilled', 'partially_paid', 'paid', 'completed'], true)) {
            return response()->json([
                'message' => 'An invoice exists only once the order is fulfilled.',
                'code' => 'NOT_FULFILLED',
            ], 422);
        }

        return response()->json([
            'invoice_number' => 'INV-'.$order->order_number,
            'date' => $order->updated_at?->toDateString(),
            'seller' => $order->operatingUnit?->name,
            'buyer' => $order->client?->entity?->name ?? $order->buyerUnit?->name ?? 'Walk-in',
            'lines' => $order->lines->map(fn ($l) => [
                'item' => $l->inventoryItem?->name,
                'sku' => $l->inventoryItem?->code,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'line_total' => $l->lineTotal(),
                'lot_number' => $l->stockLot?->lot_number,
            ]),
            'total_amount' => (float) $order->total_amount,
            'amount_paid' => (float) $order->amount_paid,
            'outstanding' => $order->outstanding(),
            'status' => $order->status->value,
        ]);
    }
}
