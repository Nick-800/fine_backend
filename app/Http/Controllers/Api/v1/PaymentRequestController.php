<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\PaymentRequestResource;
use App\Models\PaymentRequest;
use App\Services\ImportOrderStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;

final class PaymentRequestController extends Controller
{
    public function __construct(
        private readonly ImportOrderStateService $stateService
    ) {}

    /**
     * The company-wide treasury queue: every payment request, filterable by
     * status and unit — what the treasury screen lists.
     */
    public function all(Request $request): AnonymousResourceCollection
    {
        $query = PaymentRequest::with(['importOrder', 'bankHold']);

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        return PaymentRequestResource::collection($query->latest()->get());
    }

    /**
     * Payment requests of one import order — the route carries the order id
     * and the listing is scoped to it (it used to ignore the id and return
     * everything, which made every order detail screen show all payments).
     */
    public function index(Request $request, string $id): AnonymousResourceCollection
    {
        $query = PaymentRequest::with(['importOrder', 'bankHold'])
            ->where('import_order_id', $id);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        return PaymentRequestResource::collection($query->latest()->get());
    }

    /**
     * Treasury executes a payment by request id (the route the desktop
     * client calls).
     */
    public function execute(Request $request, string $id): JsonResponse
    {
        $paymentRequest = PaymentRequest::with('bankHold')->findOrFail($id);

        return $this->runExecution($request, $paymentRequest);
    }

    /**
     * Same execution, addressed through the order (the REST-nested route).
     */
    public function process(Request $request, string $orderId, string $requestId): JsonResponse
    {
        $paymentRequest = PaymentRequest::with('bankHold')
            ->where('import_order_id', $orderId)
            ->findOrFail($requestId);

        return $this->runExecution($request, $paymentRequest);
    }

    private function runExecution(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $request->validate([
            'fx_rate_used' => 'required|numeric|min:0.000001',
            'exact_amount_used_lyd' => 'nullable|numeric|min:0',
            'bank_reference' => 'nullable|string',
        ]);

        try {
            $updated = $this->stateService->executePayment(
                $paymentRequest,
                (float) $request->input('fx_rate_used'),
                $request->filled('exact_amount_used_lyd') ? (float) $request->input('exact_amount_used_lyd') : null,
                $request->input('bank_reference')
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_PAYMENT_OPERATION'], 422);
        }

        return response()->json([
            'message' => 'Payment executed successfully.',
            'data' => new PaymentRequestResource($updated->load('bankHold')),
        ]);
    }
}
