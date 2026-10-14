<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Services\StockMovementCardService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StockMovementCardController extends Controller
{
    use ResolvesReportScope;

    public function __construct(
        private readonly StockMovementCardService $movementCardService,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date',
            'warehouse_id' => 'sometimes|nullable|uuid|exists:warehouses,id',
        ]);

        $unitId = $this->resolveReportUnitId($request, $this->unitContext);

        $report = $this->movementCardService->movementCard(
            itemId: $id,
            from: $request->query('from'),
            to: $request->query('to'),
            warehouseId: $request->query('warehouse_id'),
            operatingUnitId: $unitId,
        );

        return response()->json($report);
    }
}
