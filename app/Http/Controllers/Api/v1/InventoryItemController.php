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
        $query = InventoryItem::with(['category']);

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
                    ->orWhere('code', 'like', "%{$search}%");
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
            'code' => ['required', 'string', 'max:100', 'unique:inventory_items,code'],
            'item_type' => ['required', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'unit_of_measure' => ['required', 'string'],
            'primary_uom' => ['nullable', 'string'],
            'secondary_uom' => ['nullable', 'string'],
            // Container semantics: how much one container holds, and which item
            // represents it once empty.
            'container_capacity' => ['nullable', 'numeric', 'gt:0'],
            'empty_container_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
            'default_attributes' => ['nullable', 'array'],
            // Same column names as StockLot's own length_m/width_m/height_m,
            // which carries them per physical lot instead of per catalog item.
            'length_m' => ['nullable', 'numeric', 'min:0'],
            'width_m' => ['nullable', 'numeric', 'min:0'],
            'height_m' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item = InventoryItem::create($validated);

        return response()->json($item->load(['category']), 201);
    }

    public function show(string $id): JsonResponse
    {
        $item = InventoryItem::with(['category'])->findOrFail($id);

        return response()->json($item);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);

        $validated = $request->validate([
            'category_id' => ['nullable', 'uuid', new ExistsInCurrentUnit(ItemCategory::class, 'category')],
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:100', "unique:inventory_items,code,{$id}"],
            'item_type' => ['sometimes', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'unit_of_measure' => ['sometimes', 'string'],
            'primary_uom' => ['nullable', 'string'],
            'secondary_uom' => ['nullable', 'string'],
            // Container semantics: how much one container holds, and which item
            // represents it once empty.
            'container_capacity' => ['nullable', 'numeric', 'gt:0'],
            'empty_container_item_id' => ['nullable', 'uuid', 'exists:inventory_items,id'],
            'default_attributes' => ['nullable', 'array'],
            'length_m' => ['nullable', 'numeric', 'min:0'],
            'width_m' => ['nullable', 'numeric', 'min:0'],
            'height_m' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item->update($validated);

        return response()->json($item->load(['category']));
    }

    public function destroy(string $id): JsonResponse
    {
        $item = InventoryItem::findOrFail($id);
        $item->delete();

        return response()->json(['message' => 'Inventory item soft deleted.']);
    }
}
