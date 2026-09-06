<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\OverheadAllocation;
use App\Services\AllocationNoResponsibleUserException;
use App\Services\AllocationNotResponsibleException;
use App\Services\AllocationPaymentService;
use App\Services\InvalidAllocationTransitionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OverheadAllocationController extends Controller
{
    public function __construct(
        private readonly AllocationPaymentService $service,
    ) {}

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'approve');
    }

    public function markPaid(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, 'markPaid');
    }

    private function decide(Request $request, string $id, string $action): JsonResponse
    {
        $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $allocation = OverheadAllocation::findOrFail($id);

        try {
            $allocation = $action === 'approve'
                ? $this->service->approve($allocation, $request->user(), $request->input('note'))
                : $this->service->markPaid($allocation, $request->user(), $request->input('note'));
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

        return response()->json($allocation->load(['operatingUnit', 'approver', 'payer']));
    }
}
