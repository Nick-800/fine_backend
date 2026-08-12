<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\CreditApprovalRequest;
use App\Services\SalesOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditApprovalController extends Controller
{
    public function __construct(public SalesOrderService $salesOrderService) {}

    public function index(Request $request): JsonResponse
    {
        $query = CreditApprovalRequest::with(['salesOrder.client.entity', 'decidedBy'])->latest();

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $approval = CreditApprovalRequest::findOrFail($id);

        return response()->json(
            $this->salesOrderService->decideCreditApproval(
                $approval, true, $request->user(), $request->input('notes'),
            )
        );
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $approval = CreditApprovalRequest::findOrFail($id);

        return response()->json(
            $this->salesOrderService->decideCreditApproval(
                $approval, false, $request->user(), $request->input('notes'),
            )
        );
    }
}
