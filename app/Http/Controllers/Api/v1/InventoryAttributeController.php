<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\InventoryAttributeDefinition;
use App\Models\ItemCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class InventoryAttributeController extends Controller
{
    public function index(): JsonResponse
    {
        $attributes = InventoryAttributeDefinition::orderBy('name')->get();

        return response()->json($attributes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'uuid', 'exists:item_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:50', 'unique:inventory_attribute_definitions,slug'],
            'data_type' => ['required', 'string', 'in:number,text,select,boolean'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'options' => ['nullable', 'array'],
            'is_required_on_lot' => ['boolean'],
            'is_filterable' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $validated['slug'] = Str::slug($validated['slug'] ?? $validated['name'], '_');

        $attribute = InventoryAttributeDefinition::create($validated);

        return response()->json($attribute, 201);
    }

    public function indexForCategory(string $categoryId): JsonResponse
    {
        $category = ItemCategory::findOrFail($categoryId);

        return response()->json($category->attributeDefinitions);
    }

    public function storeForCategory(Request $request, string $categoryId): JsonResponse
    {
        $category = ItemCategory::findOrFail($categoryId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:50', 'unique:inventory_attribute_definitions,slug'],
            'data_type' => ['required', 'string', 'in:number,text,select,boolean'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'options' => ['nullable', 'array'],
            'is_required_on_lot' => ['boolean'],
            'is_filterable' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $validated['slug'] = Str::slug($validated['slug'] ?? $validated['name'], '_');
        $validated['category_id'] = $category->id;

        $attribute = InventoryAttributeDefinition::create($validated);

        return response()->json($attribute, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $attribute = InventoryAttributeDefinition::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'data_type' => ['sometimes', 'string', 'in:number,text,select,boolean'],
            'unit_of_measure' => ['nullable', 'string', 'max:50'],
            'options' => ['nullable', 'array'],
            'is_required_on_lot' => ['boolean'],
            'is_filterable' => ['boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $attribute->update($validated);

        return response()->json($attribute);
    }

    public function destroy(string $id): JsonResponse
    {
        $attribute = InventoryAttributeDefinition::findOrFail($id);
        $attribute->delete();

        return response()->json(['message' => 'Attribute definition deleted successfully.']);
    }
}
