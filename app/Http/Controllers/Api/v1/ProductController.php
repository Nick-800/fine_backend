<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(public CurrentUnitContext $unitContext) {}

    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['inventoryItem', 'activeBom'])->withCount('boms')->orderBy('name');

        if (filled($request->query('search'))) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inventory_item_id' => ['required', 'uuid', 'exists:inventory_items,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', 'unique:products,sku'],
            'description' => ['nullable', 'string'],
            'markup_factor' => ['nullable', 'numeric', 'gte:1'],
        ]);

        if ($this->unitContext->getUnitId() === null) {
            return response()->json([
                'message' => 'Select an operating unit before creating a product.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $product = Product::create($validated);

        return response()->json($product->load('inventoryItem'), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(
            Product::with(['inventoryItem', 'boms.componentLines.inventoryItem', 'boms.laborRequirements'])
                ->findOrFail($id)
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => ['sometimes', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product->id)],
            'description' => ['nullable', 'string'],
            'markup_factor' => ['sometimes', 'numeric', 'gte:1'],
            'record_version' => ['required', 'integer'],
        ]);

        $product->update($validated);

        return response()->json($product->fresh(['inventoryItem', 'activeBom']));
    }

    public function destroy(string $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        if ($product->boms()->whereHas('componentLines')->exists()) {
            // Soft delete keeps history; BOMs and past orders stay traceable.
            $product->delete();

            return response()->json(['message' => 'Product archived; its BOM history is retained.']);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    public function boms(string $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        return response()->json(
            $product->boms()->with(['componentLines.inventoryItem', 'laborRequirements'])->get()
        );
    }
}
