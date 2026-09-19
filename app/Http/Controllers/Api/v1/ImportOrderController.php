<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\ImportOrderStatus;
use App\Enums\PaymentRoute;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreImportOrderRequest;
use App\Http\Requests\v1\UpdateImportOrderRequest;
use App\Http\Resources\v1\ImportOrderResource;
use App\Models\FxRate;
use App\Models\ImportOrder;
use App\Models\ImportOrderItem;
use App\Models\InventoryItem;
use App\Models\OperatingUnit;
use App\Models\Warehouse;
use App\Rules\ExistsInCurrentUnit;
use App\Services\ImportOrderStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ImportOrderController extends Controller
{
    /**
     * Procurement-sensible item types — items used to source a purchase order.
     * Foam blocks, finished goods, slices, etc. are produced internally and
     * cannot be procured via an import order.
     */
    private const PROCUREMENT_ITEM_TYPES = [
        'raw_material',
        'packaging',
        'barrel',
        'pallet',
    ];

    public function __construct(
        private readonly ImportOrderStateService $stateService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ImportOrder::with([
            'supplier',
            'paymentRequests',
            'landedCostLines',
            'goodsReceipt',
            'items.inventoryItem',
        ]);

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        return ImportOrderResource::collection($query->latest()->get());
    }

    public function store(StoreImportOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        // PROC-06: the booked FX estimate is captured when the order is
        // booked, not reconstructed later. Completion posts FX gain/loss
        // against this snapshot (phase-02 §2.8).
        if (! isset($data['booked_fx_rate'])) {
            $data['booked_fx_rate'] = $this->snapshotBookedFxRate(
                $data['currency'] ?? 'USD',
                $data['operating_unit_id'],
            );
        }

        return DB::transaction(function () use ($request, $data) {
            $currency = $data['currency'] ?? 'USD';

            // When line items are supplied, derive the header aggregates from
            // them so the existing completion / goods-receipt / landed-cost
            // flows continue to work unchanged.
            $headerQuantity = $data['quantity'] ?? null;
            $headerUnitPrice = $data['negotiated_price'] ?? null;

            if ($request->has('items')) {
                $items = $this->validateAndPrepareItems($request->input('items'), $currency);

                $headerQuantity = array_sum(array_column($items, 'quantity'));
                $headerUnitPrice = array_sum(array_map(
                    fn ($line) => $line['quantity'] * $line['unit_price'],
                    $items,
                ));
            }

            $order = ImportOrder::create([
                'operating_unit_id' => $data['operating_unit_id'],
                'supplier_id' => $data['supplier_id'],
                'currency' => $currency,
                'negotiated_price' => $headerUnitPrice ?? 0,
                'quantity' => $headerQuantity ?? 0,
                'booked_fx_rate' => $data['booked_fx_rate'] ?? null,
            ]);

            if ($request->has('items')) {
                foreach ($items as $line) {
                    ImportOrderItem::create($line + ['import_order_id' => $order->id]);
                }
            }

            return (new ImportOrderResource($order->load([
                'supplier',
                'items.inventoryItem',
            ])))->response()->setStatusCode(201);
        });
    }

    public function update(UpdateImportOrderRequest $request, string $id): JsonResponse|ImportOrderResource
    {
        $order = ImportOrder::findOrFail($id);

        if ($order->status !== ImportOrderStatus::Draft) {
            return response()->json([
                'message' => 'Items can only be edited when the import order is in draft status.',
                'code' => 'ORDER_NOT_IN_DRAFT',
            ], 422);
        }

        $items = $this->validateAndPrepareItems($request->input('items'), $order->currency);

        return DB::transaction(function () use ($order, $items, $request) {
            $order->items()->delete();

            foreach ($items as $line) {
                ImportOrderItem::create($line + ['import_order_id' => $order->id]);
            }

            $headerQuantity = array_sum(array_column($items, 'quantity'));
            $headerUnitPrice = array_sum(array_map(
                fn ($line) => $line['quantity'] * $line['unit_price'],
                $items,
            ));

            $attributes = [
                'quantity' => $headerQuantity,
                'negotiated_price' => $headerUnitPrice,
            ];

            if ($request->filled('supplier_id')) {
                $attributes['supplier_id'] = $request->input('supplier_id');
            }

            $order->update($attributes);

            return new ImportOrderResource($order->fresh([
                'supplier',
                'items.inventoryItem',
            ]));
        });
    }

    /**
     * Ensure every referenced inventory item is procurement-eligible (i.e.
     * not foam block / finished good / etc.). Reuses the validated uuids
     * already produced by the FormRequest.
     *
     * @param  array<int, array{inventory_item_id: string, quantity: float|int|string, unit_price: float|int|string}>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function validateAndPrepareItems(array $lines, string $currency): array
    {
        $ids = array_column($lines, 'inventory_item_id');
        $items = InventoryItem::whereIn('id', $ids)->get()->keyBy('id');

        $prepared = [];
        foreach ($lines as $line) {
            $item = $items[$line['inventory_item_id']] ?? null;
            if (! $item) {
                // FormRequest already validated exists — this is defense in depth.
                abort(422, 'Unknown inventory item: '.$line['inventory_item_id']);
            }
            if (! in_array($item->item_type, self::PROCUREMENT_ITEM_TYPES, true)) {
                abort(response()->json([
                    'message' => "Item \"{$item->name}\" is type \"{$item->item_type}\" which is not procurement-eligible.",
                    'code' => 'INVALID_ITEM_TYPE',
                ], 422));
            }

            $prepared[] = [
                'inventory_item_id' => $item->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'currency' => $currency,
            ];
        }

        return $prepared;
    }

    private function snapshotBookedFxRate(string $currency, string $operatingUnitId): ?string
    {
        $functionalCurrency = OperatingUnit::query()
            ->find($operatingUnitId)?->company?->default_currency ?? 'LYD';

        if ($currency === $functionalCurrency) {
            return '1';
        }

        return FxRate::query()
            ->where('from_currency', $currency)
            ->where('to_currency', $functionalCurrency)
            ->latest('captured_at')
            ->value('rate');
    }

    public function show(string $id): ImportOrderResource
    {
        $order = ImportOrder::with([
            'supplier',
            'paymentRequests.bankHold',
            'landedCostLines',
            'goodsReceipt',
            'items.inventoryItem',
        ])->findOrFail($id);

        return new ImportOrderResource($order);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $order = ImportOrder::findOrFail($id);

        // receive_goods: if no explicit warehouse is sent but we already
        // recorded one on arrival, default to it so the operator doesn't
        // have to re-pick the same warehouse.
        if ($request->input('action') === 'receive_goods'
            && ! $request->filled('warehouse_id')
            && $order->arrived_warehouse_id
        ) {
            $request->merge(['warehouse_id' => $order->arrived_warehouse_id]);
        }

        $request->validate([
            'action' => 'required|string|in:pending_payment,select_route,shipment,arrive_port,arrived_at_warehouse,transport_warehouse,receive_goods,complete',
            'route' => 'required_if:action,select_route|string|in:bank,market',
            'amount_requested' => 'required_if:action,select_route|numeric|min:0.0001',
            'held_amount_lyd' => 'required_if:route,bank|nullable|numeric|min:0.0001',
            'invoice_ref' => 'nullable|string',
            'warehouse_id' => ['required_if:action,arrived_at_warehouse,receive_goods', 'nullable', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
            'received_qty' => 'required_if:action,receive_goods|nullable|numeric|min:0.0001',
            'condition_notes' => 'nullable|string',
        ]);

        $action = $request->input('action');

        try {
            match ($action) {
                'pending_payment' => $this->stateService->transitionToPendingPayment($order),
                'select_route' => $this->stateService->selectPaymentRoute(
                    $order,
                    PaymentRoute::from($request->input('route')),
                    (float) $request->input('amount_requested'),
                    $request->filled('held_amount_lyd') ? (float) $request->input('held_amount_lyd') : null,
                    $request->input('invoice_ref')
                ),
                'shipment' => $this->stateService->confirmShipment($order),
                'arrive_port' => $this->stateService->arriveAtPort($order),
                'arrived_at_warehouse' => $this->stateService->arriveAtWarehouse(
                    $order,
                    $request->input('warehouse_id')
                ),
                'transport_warehouse' => $this->stateService->transportToWarehouse($order),
                'receive_goods' => $this->stateService->receiveGoods(
                    $order,
                    $request->input('warehouse_id'),
                    (float) $request->input('received_qty'),
                    $request->input('condition_notes')
                ),
                'complete' => $this->stateService->completeOrder($order),
            };
        } catch (InvalidArgumentException $e) {
            // Wrong-state guards throw InvalidStateTransitionException and
            // render 422 globally; this catches the input-shaped refusals
            // (missing hold amount, unconfirmed cost lines, unpostable
            // completion) so they are 422s too, not 500s.
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_IMPORT_ORDER_OPERATION',
            ], 422);
        }

        return response()->json([
            'message' => 'Transition applied successfully.',
            'data' => new ImportOrderResource($order->fresh([
                'supplier',
                'paymentRequests.bankHold',
                'landedCostLines',
                'goodsReceipt',
                'items.inventoryItem',
                'arrivedWarehouse',
            ])),
        ]);
    }
}
