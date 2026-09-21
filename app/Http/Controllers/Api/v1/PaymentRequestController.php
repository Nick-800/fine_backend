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
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PaymentRequestController extends Controller
{
    public function __construct(
        private readonly ImportOrderStateService $stateService
    ) {}

    /**
     * The company-wide treasury queue: every payment request, filterable by
     * status, route, and unit — what the treasury screen lists.
     */
    public function all(Request $request): AnonymousResourceCollection
    {
        $query = PaymentRequest::with(['importOrder.supplier', 'bankHold']);

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('route')) {
            $query->where('route', $request->query('route'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->query('to'));
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
        $query = PaymentRequest::with(['importOrder.supplier', 'bankHold'])
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
        $paymentRequest = PaymentRequest::with(['bankHold', 'importOrder.supplier'])->findOrFail($id);

        return $this->runExecution($request, $paymentRequest);
    }

    /**
     * Same execution, addressed through the order (the REST-nested route).
     */
    public function process(Request $request, string $orderId, string $requestId): JsonResponse
    {
        $paymentRequest = PaymentRequest::with(['bankHold', 'importOrder.supplier'])
            ->where('import_order_id', $orderId)
            ->findOrFail($requestId);

        return $this->runExecution($request, $paymentRequest);
    }

    private function runExecution(Request $request, PaymentRequest $paymentRequest): JsonResponse
    {
        $user = $request->user();
        $isAuthorized = $user && (
            $user->hasRole('owner')
            || $user->hasRole('admin')
            || $user->hasRole('accounting-manager')
            || $user->hasRole('treasury-officer')
            || $user->hasRole('procurement-manager')
            || $user->hasRole('hr-manager')
            || $user->hasRole('inventory-manager')
            || $user->hasRole('foam-manager')
            || $user->hasRole('cutter-manager')
            || $user->hasRole('furniture-manager')
            || $user->hasRole('store-manager')
            || $user->hasRole('unit_manager')
            || $user->hasRole('manager')
        );

        if (! $isAuthorized) {
            return response()->json([
                'message' => 'Only managers and finance officers can execute payments.',
                'code' => 'FINANCE_ONLY_EXECUTION',
            ], 403);
        }

        $request->validate([
            'fx_rate_used' => 'required_without:exact_amount_used_lyd|nullable|numeric|min:0.000001',
            'exact_amount_used_lyd' => 'required_without:fx_rate_used|nullable|numeric|min:0',
            'bank_reference' => 'nullable|string',
            'extra_allocation_note' => 'nullable|string|max:500',
        ]);

        // FX-04 / FX-24: variance is measured in LYD against the booked
        // expectation using the effective settled (LYD wins if supplied),
        // not the rate difference. The note is required when actual settled
        // deviates from booked by more than the tolerance band.
        $fxRateUsed = $request->filled('fx_rate_used') ? (float) $request->input('fx_rate_used') : null;
        $exactUsed = $request->filled('exact_amount_used_lyd') ? (float) $request->input('exact_amount_used_lyd') : null;

        ['effective_settled' => $effectiveSettled] =
            $this->stateService->deriveEffectiveValues($paymentRequest, $fxRateUsed, $exactUsed);

        $functionalCurrency = $paymentRequest->importOrder?->operatingUnit?->company?->default_currency ?? 'LYD';
        $varianceLyd = $this->stateService->varianceVsBooked(
            $paymentRequest->importOrder,
            $effectiveSettled,
            $functionalCurrency,
        );
        $tolerance = ImportOrderStateService::fxToleranceLyd();

        if ($varianceLyd !== null && abs($varianceLyd) > $tolerance && blank($request->input('extra_allocation_note'))) {
            throw ValidationException::withMessages([
                'extra_allocation_note' => sprintf(
                    'سبب التكلفة الإضافية مطلوب عند فرق %.2f دينار عن السعر المرجعي.',
                    $varianceLyd,
                ),
            ]);
        }

        try {
            $updated = $this->stateService->executePayment(
                $paymentRequest,
                $fxRateUsed,
                $exactUsed,
                $request->input('bank_reference'),
                $request->input('extra_allocation_note')
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
