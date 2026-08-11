<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\InventoryValuationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryValuationController extends Controller
{
    public function __construct(public InventoryValuationService $valuationService) {}

    public function index(Request $request): JsonResponse
    {
        $unitId = $request->header('X-Operating-Unit-ID') ?? $request->query('operating_unit_id');

        if (! $unitId) {
            return response()->json(['message' => 'X-Operating-Unit-ID header or operating_unit_id query parameter is required.'], 400);
        }

        $valuation = $this->valuationService->getUnitValuation((string) $unitId);

        return response()->json($valuation);
    }

    public function rollup(): JsonResponse
    {
        $rollup = $this->valuationService->getCompanyRollup();

        return response()->json($rollup);
    }
}
