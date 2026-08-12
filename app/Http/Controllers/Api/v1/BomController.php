<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Bom;
use App\Services\ProductionOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BomController extends Controller
{
    public function __construct(public ProductionOrderService $productionOrderService) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'notes' => ['nullable', 'string'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $version = ((int) Bom::withTrashed()
            ->where('product_id', $validated['product_id'])
            ->max('version')) + 1;

        $bom = Bom::create([
            'product_id' => $validated['product_id'],
            'version' => $version,
            'notes' => $validated['notes'] ?? null,
        ]);

        if ($request->boolean('activate')) {
            $bom = $this->productionOrderService->activateBom($bom);
        }

        return response()->json($bom, 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            Bom::with(['product', 'componentLines.inventoryItem', 'laborRequirements', 'clonedFrom'])
                ->findOrFail($id)
        );
    }

    public function activate(string $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);

        return response()->json($this->productionOrderService->activateBom($bom));
    }

    /**
     * FUR-03: clone for custom-order adaptation. The copy starts inactive.
     */
    public function clone(string $id): JsonResponse
    {
        $source = Bom::with(['componentLines', 'laborRequirements'])->findOrFail($id);

        return response()->json($this->productionOrderService->cloneBom($source), 201);
    }

    public function storeComponentLine(Request $request, string $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);

        $validated = $request->validate([
            'inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'estimated_unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json($bom->componentLines()->create($validated)->load('inventoryItem'), 201);
    }

    public function destroyComponentLine(string $id, string $lineId): JsonResponse
    {
        Bom::findOrFail($id)->componentLines()->whereKey($lineId)->delete();

        return response()->json(['message' => 'Component removed.']);
    }

    public function storeLaborRequirement(Request $request, string $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);

        $validated = $request->validate([
            'role' => ['required', 'string', 'max:50'],
            'estimated_hours' => ['required', 'numeric', 'gt:0'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
        ]);

        return response()->json($bom->laborRequirements()->create($validated), 201);
    }

    public function destroyLaborRequirement(string $id, string $reqId): JsonResponse
    {
        Bom::findOrFail($id)->laborRequirements()->whereKey($reqId)->delete();

        return response()->json(['message' => 'Labor requirement removed.']);
    }

    /**
     * Quote-side cost breakdown: estimates × markup. Actual order costing uses
     * real lot costs at consumption and may differ.
     */
    public function pricePreview(string $id): JsonResponse
    {
        $bom = Bom::with(['componentLines', 'laborRequirements', 'product'])->findOrFail($id);

        $material = $bom->estimatedMaterialCost();
        $labor = $bom->estimatedLaborCost();
        $total = round($material + $labor, 4);
        $markup = (float) ($bom->product->markup_factor ?? 1);

        return response()->json([
            'estimated_material_cost' => $material,
            'estimated_labor_cost' => $labor,
            'estimated_total_cost' => $total,
            'markup_factor' => $markup,
            'suggested_price' => round($total * $markup, 2),
        ]);
    }
}
