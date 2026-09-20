<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Rules\ExistsInCurrentUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InventoryItem::with(['category', 'attributeDefinitions']);

        if ($request->has('category_id') && filled($request->query('category_id'))) {
            $query->where('category_id', $request->query('category_id'));
        }

        if ($request->has('item_type') && filled($request->query('item_type'))) {
            $query->where('item_type', $request->query('item_type'));
        }

        if ($request->has('search') && filled($request->query('search'))) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('name')->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        if (empty($request->input('item_type')) && $request->filled('category_id')) {
            $category = ItemCategory::find($request->input('category_id'));
            if ($category && ! empty($category->item_type)) {
                $request->merge(['item_type' => $category->item_type]);
            }
        }

        $validated = $request->validate([
            'category_id' => ['nullable', 'uuid', new ExistsInCurrentUnit(ItemCategory::class, 'category')],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', 'unique:inventory_items,sku'],
            'item_type' => ['required', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'unit_of_measure' => ['required', 'string'],
            'primary_uom' => ['nullable', 'string'],
            'secondary_uom' => ['nullable', 'string'],
            // Container semantics: how much one container holds, and which item
            // represents it once empty.
            'container_capacity' => ['nullable', 'numeric', 'gt:0'],
            'empty_container_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
            'default_attributes' => ['nullable', 'array'],
            'attribute_definition_ids' => ['nullable', 'array'],
            'attribute_definition_ids.*' => ['uuid', 'exists:inventory_attribute_definitions,id'],
        ]);

        $item = InventoryItem::create($validated);

        if (! empty($validated['attribute_definition_ids'])) {
            $item->attributeDefinitions()->sync($validated['attribute_definition_ids']);
        }

        return response()->json($item->load(['category', 'attributeDefinitions']), 201);
    }

    public function show(string $id): JsonResponse
    {
        $item = InventoryItem::with(['category', 'attributeDefinitions'])->findOrFail($id);

        return response()->json($item);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);

        $validated = $request->validate([
            'category_id' => ['nullable', 'uuid', new ExistsInCurrentUnit(ItemCategory::class, 'category')],
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => ['sometimes', 'string', 'max:100', "unique:inventory_items,sku,{$id}"],
            'item_type' => ['sometimes', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'unit_of_measure' => ['sometimes', 'string'],
            'primary_uom' => ['nullable', 'string'],
            'secondary_uom' => ['nullable', 'string'],
            // Container semantics: how much one container holds, and which item
            // represents it once empty.
            'container_capacity' => ['nullable', 'numeric', 'gt:0'],
            'empty_container_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
            'default_attributes' => ['nullable', 'array'],
            'attribute_definition_ids' => ['nullable', 'array'],
            'attribute_definition_ids.*' => ['uuid', 'exists:inventory_attribute_definitions,id'],
        ]);

        $item->update($validated);

        if (array_key_exists('attribute_definition_ids', $validated)) {
            $item->attributeDefinitions()->sync($validated['attribute_definition_ids'] ?? []);
        }

        return response()->json($item->load(['category', 'attributeDefinitions']));
    }

    public function destroy(string $id): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);
        $item->delete();

        return response()->json(['message' => 'Inventory item soft deleted.']);
    }
}
