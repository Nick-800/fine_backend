<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\FinancialReportService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinancialReportController extends Controller
{
    public function __construct(
        private readonly FinancialReportService $reports,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function incomeStatement(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date',
        ]);

        return response()->json($this->reports->incomeStatement(
            $request->query('from'),
            $request->query('to'),
            $this->resolveUnitId($request),
        ));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $request->validate([
            'as_of' => 'sometimes|nullable|date',
        ]);

        return response()->json($this->reports->balanceSheet(
            $request->query('as_of'),
            $this->resolveUnitId($request),
        ));
    }

    public function unitProfitability(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date',
        ]);

        return response()->json($this->reports->unitProfitability(
            $request->query('from'),
            $request->query('to'),
            $this->resolveUnitId($request),
        ));
    }

    /**
     * Owner sees company-wide; a unit-scoped caller sees their own numbers —
     * the same rule the trial balance follows.
     */
    private function resolveUnitId(Request $request): ?string
    {
        return $request->query('operating_unit_id') ?? $this->unitContext->getUnitId();
    }
}
