<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\ItemCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItemCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ItemCategory::with('attributeDefinitions');

        if ($request->has('search') && filled($request->query('search'))) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Unit comes from the role-validated request context, not the body.
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:item_categories,code'],
            'item_type' => ['nullable', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'description' => ['nullable', 'string'],
        ]);

        $category = ItemCategory::create($validated);

        return response()->json($category->load('attributeDefinitions'), 201);
    }

    public function show(string $id): JsonResponse
    {
        $category = ItemCategory::with('attributeDefinitions')->findOrFail($id);

        return response()->json($category);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = ItemCategory::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', "unique:item_categories,code,{$id}"],
            'item_type' => ['nullable', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'description' => ['nullable', 'string'],
        ]);

        $category->update($validated);

        return response()->json($category->load('attributeDefinitions'));
    }

    public function destroy(string $id): JsonResponse
    {
        $category = ItemCategory::findOrFail($id);
        $category->delete();

        return response()->json(['message' => 'Item category deleted successfully.']);
    }
}
