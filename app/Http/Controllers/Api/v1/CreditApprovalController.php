<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\CreditApprovalRequest;
use App\Services\SaleCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Over-limit receivable sales waiting on a manager (SALE-02). Requests carry
 * no unit of their own; whereHas('salesOrder') applies the sale's unit scope,
 * so a manager only sees and decides their own unit's requests.
 */
class CreditApprovalController extends Controller
{
    public function __construct(public SaleCheckoutService $checkoutService) {}

    public function index(Request $request): JsonResponse
    {
        $query = CreditApprovalRequest::with(['salesOrder.client.entity', 'salesOrder.lines', 'decidedBy:id,name'])
            ->whereHas('salesOrder')
            ->latest();

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $approval = CreditApprovalRequest::whereHas('salesOrder')->findOrFail($id);

        return response()->json(
            $this->checkoutService->approveCredit($approval, $request->user(), $request->input('notes'))
        );
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $approval = CreditApprovalRequest::whereHas('salesOrder')->findOrFail($id);

        return response()->json(
            $this->checkoutService->rejectCredit($approval, $request->user(), $request->input('notes'))
        );
    }
}
