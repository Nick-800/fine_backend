<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\ConsumptionReport;
use App\Models\ProductionBatch;
use App\Services\ConsumptionReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsumptionReportController extends Controller
{
    public function __construct(public ConsumptionReportService $consumptionReportService) {}

    public function show(string $batchId): JsonResponse
    {
        $batch = ProductionBatch::findOrFail($batchId);

        $report = ConsumptionReport::with(['lines.chemicalItem'])
            ->where('production_batch_id', $batch->id)
            ->first();

        if ($report === null) {
            return response()->json([
                'message' => "Operation {$batch->operation_number} has no consumption report yet.",
                'code' => 'NO_CONSUMPTION_REPORT',
            ], 404);
        }

        return response()->json([
            'report' => $report,
            'material_cost' => $report->materialCost(),
        ]);
    }

    public function store(Request $request, string $batchId): JsonResponse
    {
        $batch = ProductionBatch::findOrFail($batchId);

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.chemical_inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            // Zero is valid: the production report lists optional inputs that a
            // given run may not have used.
            'lines.*.quantity_consumed' => ['required', 'numeric', 'min:0'],
        ]);

        $report = $this->consumptionReportService->record($batch, $validated['lines']);

        return response()->json([
            'report' => $report->load('lines.chemicalItem'),
            'material_cost' => $report->materialCost(),
            'batch' => $batch->fresh(),
        ], 201);
    }
}
