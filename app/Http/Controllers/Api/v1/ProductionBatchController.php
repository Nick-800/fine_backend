<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\ProductionBatchStatus;
use App\Http\Controllers\Controller;
use App\Models\ProductionBatch;
use App\Models\Warehouse;
use App\Rules\ExistsInCurrentUnit;
use App\Services\ProductionBatchService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductionBatchController extends Controller
{
    public function __construct(
        public ProductionBatchService $productionBatchService,
        public CurrentUnitContext $unitContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ProductionBatch::with(['operatingUnit', 'requestedByClient'])
            ->withCount('stockLots');

        if ($unitId = $this->unitContext->getUnitId()) {
            $query->where('operating_unit_id', $unitId);
        }

        if (filled($request->query('status'))) {
            $query->where('status', $request->query('status'));
        }

        return response()->json(
            $query->orderByDesc('operation_number')->paginate($request->integer('per_page', 15))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `unique` deliberately spans soft-deleted rows — a deleted batch may
            // still have labelled blocks in the yard.
            'operation_number' => ['required', 'integer', 'min:1', 'unique:production_batches,operation_number'],
            'bun_width_m' => ['required', 'numeric', 'min:0.001'],
            'requested_by_client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'formula_params' => ['nullable', 'array'],
            'status' => ['nullable', Rule::enum(ProductionBatchStatus::class)],
            'material_cost' => ['nullable', 'numeric', 'min:0'],
            'confirm_non_sequential' => ['nullable', 'boolean'],
        ]);

        // The unit comes only from the request context, which ScopeOperatingUnit has
        // already validated against the user's roles. Accepting it in the body would
        // let a unit-scoped user create a batch inside a unit they cannot reach.
        //
        // Company-wide roles (Owner) carry no unit of their own, so they must name one
        // via the header — otherwise operating_unit_id would be null against a NOT NULL
        // column and surface as a 500 rather than something the operator can act on.
        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Select an operating unit before creating a production batch. Company-wide roles must send an X-Operating-Unit-ID header naming the unit that ran the pour.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $entered = (int) $validated['operation_number'];
        $expected = $this->productionBatchService->nextExpectedOperationNumber();

        // Warn, never block: legitimate gaps happen, and a hard rule would only
        // train operators to work around the system. But an unconfirmed gap is
        // far more likely to be a transposition typo than a real skip.
        if ($entered !== $expected && ! $request->boolean('confirm_non_sequential')) {
            return response()->json([
                'message' => "Last operation was {$expected}, you entered {$entered}. Confirm to continue.",
                'code' => 'NON_SEQUENTIAL_OPERATION_NUMBER',
                'expected_operation_number' => $expected,
                'entered_operation_number' => $entered,
            ], 422);
        }

        $batch = ProductionBatch::create([
            ...$validated,
            'operating_unit_id' => $unitId,
        ]);

        return response()->json($batch->load(['operatingUnit', 'requestedByClient']), 201);
    }

    public function show(string $id): JsonResponse
    {
        $batch = ProductionBatch::with(['operatingUnit', 'requestedByClient'])
            ->withCount('stockLots')
            ->findOrFail($id);

        return response()->json($batch);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $batch = ProductionBatch::findOrFail($id);

        $validated = $request->validate([
            'operation_number' => [
                'sometimes', 'integer', 'min:1',
                Rule::unique('production_batches', 'operation_number')->ignore($batch->id),
            ],
            'bun_width_m' => ['sometimes', 'numeric', 'min:0.001'],
            'requested_by_client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'formula_params' => ['nullable', 'array'],
            'status' => ['sometimes', Rule::enum(ProductionBatchStatus::class)],
            'material_cost' => ['sometimes', 'numeric', 'min:0'],
            'record_version' => ['required', 'integer'],
        ]);

        // Once a block is registered its label is printed and in the yard, so the
        // operation number it encodes can no longer move.
        if (array_key_exists('operation_number', $validated)
            && (int) $validated['operation_number'] !== (int) $batch->operation_number
            && $batch->stockLots()->exists()) {
            return response()->json([
                'message' => 'Operation number cannot change once blocks are registered — their printed labels already encode it.',
                'code' => 'OPERATION_NUMBER_IMMUTABLE',
            ], 422);
        }

        $batch->update($validated);

        return response()->json($batch->load(['operatingUnit', 'requestedByClient']));
    }

    public function destroy(string $id): JsonResponse
    {
        $batch = ProductionBatch::findOrFail($id);

        // Blocks carry printed labels encoding this batch's operation number, so a
        // batch with output cannot be withdrawn. (Soft-deleted batches still hold
        // their operation number against reuse — see ProductionBatchService.)
        if ($batch->stockLots()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a batch that has registered blocks — their printed labels reference it.',
                'code' => 'BATCH_HAS_BLOCKS',
            ], 422);
        }

        $batch->delete();

        return response()->json(['message' => 'Production batch soft deleted.']);
    }

    /**
     * Register a run's output from its production report.
     *
     * Groups mirror the paper sheet (dimensions + count); each block group is
     * expanded into individually labelled lots.
     */
    public function registerBlocks(Request $request, string $id): JsonResponse
    {
        $batch = ProductionBatch::findOrFail($id);

        $validated = $request->validate([
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.kind' => ['required', 'string', 'in:block,scrap'],
            'groups.*.count' => ['required', 'integer', 'min:1'],
            'groups.*.length_m' => ['required', 'numeric', 'min:0.001'],
            'groups.*.height_m' => ['required', 'numeric', 'min:0.001'],
            'groups.*.pressure' => ['required_if:groups.*.kind,block', 'integer', 'min:1'],
            'groups.*.inventory_item_id' => ['required_if:groups.*.kind,block', 'uuid', 'exists:inventory_items,id'],
            'groups.*.warehouse_id' => ['required_if:groups.*.kind,block', 'uuid', new ExistsInCurrentUnit(Warehouse::class, 'warehouse')],
            'groups.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'groups.*.grade' => ['nullable', 'string', 'in:standard,acceptable_variant,defective_usable,reject'],
            'groups.*.color' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->productionBatchService->registerBlocks($batch, $validated['groups']);

        return response()->json([
            'batch' => $result['batch'],
            'blocks' => $result['blocks'],
            'blocks_created' => count($result['blocks']),
            'scrap_volume_m3' => $result['scrap_volume_m3'],
        ], 201);
    }
}
