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

final class PaymentRequestController extends Controller
{
    public function __construct(
        private readonly ImportOrderStateService $stateService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
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

    public function execute(Request $request, string $id): JsonResponse
    {
        $paymentRequest = PaymentRequest::with('bankHold')->findOrFail($id);

        $request->validate([
            'fx_rate_used' => 'required|numeric|min:0.000001',
            'exact_amount_used_lyd' => 'nullable|numeric|min:0',
            'bank_reference' => 'nullable|string',
        ]);

        $updated = $this->stateService->executePayment(
            $paymentRequest,
            (float) $request->input('fx_rate_used'),
            $request->filled('exact_amount_used_lyd') ? (float) $request->input('exact_amount_used_lyd') : null,
            $request->input('bank_reference')
        );

        return response()->json([
            'message' => 'Payment executed successfully.',
            'data' => new PaymentRequestResource($updated->load('bankHold')),
        ]);
    }
}
