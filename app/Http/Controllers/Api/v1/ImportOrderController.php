<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\PaymentRoute;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreImportOrderRequest;
use App\Http\Resources\v1\ImportOrderResource;
use App\Models\ImportOrder;
use App\Services\ImportOrderStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ImportOrderController extends Controller
{
    public function __construct(
        private readonly ImportOrderStateService $stateService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ImportOrder::with(['supplier', 'paymentRequests', 'landedCostLines', 'goodsReceipt']);

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
        $order = ImportOrder::create($request->validated());

        return (new ImportOrderResource($order->load('supplier')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $id): ImportOrderResource
    {
        $order = ImportOrder::with(['supplier', 'paymentRequests.bankHold', 'landedCostLines', 'goodsReceipt'])
            ->findOrFail($id);

        return new ImportOrderResource($order);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $order = ImportOrder::findOrFail($id);

        $request->validate([
            'action' => 'required|string|in:pending_payment,select_route,shipment,arrive_port,transport_warehouse,receive_goods,complete',
            'route' => 'required_if:action,select_route|string|in:bank,market',
            'amount_requested' => 'required_if:action,select_route|numeric|min:0.0001',
            'held_amount_lyd' => 'required_if:route,bank|nullable|numeric|min:0.0001',
            'invoice_ref' => 'nullable|string',
            'warehouse_id' => 'required_if:action,receive_goods|nullable|uuid|exists:warehouses,id',
            'received_qty' => 'required_if:action,receive_goods|nullable|numeric|min:0.0001',
            'condition_notes' => 'nullable|string',
        ]);

        $action = $request->input('action');

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
            'transport_warehouse' => $this->stateService->transportToWarehouse($order),
            'receive_goods' => $this->stateService->receiveGoods(
                $order,
                $request->input('warehouse_id'),
                (float) $request->input('received_qty'),
                $request->input('condition_notes')
            ),
            'complete' => $this->stateService->completeOrder($order),
        };

        return response()->json([
            'message' => 'Transition applied successfully.',
            'data' => new ImportOrderResource($order->fresh(['supplier', 'paymentRequests.bankHold', 'landedCostLines', 'goodsReceipt'])),
        ]);
    }
}
