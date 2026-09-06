<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\LandedCostLineResource;
use App\Models\ImportOrder;
use App\Models\LandedCostLine;
use App\Services\AllocationNoResponsibleUserException;
use App\Services\AllocationNotResponsibleException;
use App\Services\AllocationPaymentService;
use App\Services\InvalidAllocationTransitionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

final class LandedCostLineController extends Controller
{
    public function __construct(
        private readonly AllocationPaymentService $service,
    ) {}

    public function index(string $orderId): AnonymousResourceCollection
    {
        $order = ImportOrder::findOrFail($orderId);

        return LandedCostLineResource::collection(
            $order->landedCostLines()->with(['approver', 'payer'])->get()
        );
    }

    public function store(Request $request, string $orderId): JsonResponse
    {
        $order = ImportOrder::findOrFail($orderId);

        $data = $request->validate([
            'type' => 'required|string|in:supplier_price,fx_spread,customs,freight,local_transport,other',
            'amount' => 'required|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'note' => 'nullable|string|max:500',
        ]);

        if (
            $data['type'] === 'fx_spread'
            && (float) $data['amount'] != 0
            && blank($data['note'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'note' => 'سبب فرق سعر الصرف مطلوب عند تسجيل قيمة غير صفرية.',
            ]);
        }

        $line = $order->landedCostLines()->create([
            'type' => $data['type'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'LYD',
            'is_confirmed' => false,
            'note' => $data['note'] ?? null,
        ]);

        return (new LandedCostLineResource($line))
            ->response()
            ->setStatusCode(201);
    }

    public function approve(Request $request, string $orderId, string $lineId): JsonResponse
    {
        return $this->decide($request, $orderId, $lineId, 'approve');
    }

    public function markPaid(Request $request, string $orderId, string $lineId): JsonResponse
    {
        return $this->decide($request, $orderId, $lineId, 'markPaid');
    }

    private function decide(Request $request, string $orderId, string $lineId, string $action): JsonResponse
    {
        $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $line = LandedCostLine::where('import_order_id', $orderId)->findOrFail($lineId);

        try {
            $line = $action === 'approve'
                ? $this->service->approve($line, $request->user(), $request->input('note'))
                : $this->service->markPaid($line, $request->user(), $request->input('note'));
        } catch (AllocationNotResponsibleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'ALLOCATION_NOT_RESPONSIBLE',
            ], 403);
        } catch (AllocationNoResponsibleUserException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'ALLOCATION_NO_RESPONSIBLE_USER',
            ], 422);
        } catch (InvalidAllocationTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'INVALID_ALLOCATION_TRANSITION',
            ], 422);
        }

        return response()->json([
            'message' => $action === 'approve' ? 'Landed cost line approved.' : 'Landed cost line paid.',
            'data' => new LandedCostLineResource($line),
        ]);
    }
}
