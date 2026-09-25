<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\ItemCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ItemCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ItemCategory::query();

        if ($request->has('search') && filled($request->query('search'))) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('root_only')) {
            $query->whereNull('parent_id');
        } elseif ($request->filled('parent_id')) {
            $query->where('parent_id', (string) $request->query('parent_id'));
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Unit comes from the role-validated request context, not the body.
            // Two ways to set the code: pass `code_segment` and it's derived
            // from the parent's code (see ItemCategory::buildCode) — the path
            // the hierarchical categories UI uses; or pass a flat `code`
            // directly for a category that doesn't participate in the
            // segment system (legacy behaviour, e.g. SystemBootstrapSeeder).
            'parent_id' => ['nullable', 'uuid', 'exists:item_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'code_segment' => ['required_without:code', 'nullable', 'string', 'max:50'],
            'code' => ['required_without:code_segment', 'nullable', 'string', 'max:50', 'unique:item_categories,code'],
            'item_type' => ['nullable', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'child_code_length' => ['nullable', 'integer', 'min:1', 'max:10'],
            'description' => ['nullable', 'string'],
        ]);

        if (isset($validated['code_segment'])) {
            $this->assertCodeAvailable(
                ItemCategory::buildCode($validated['parent_id'] ?? null, $validated['code_segment']),
            );
        }

        $category = ItemCategory::create($validated);

        return response()->json($category, 201);
    }

    public function show(string $id): JsonResponse
    {
        $category = ItemCategory::findOrFail($id);

        return response()->json($category);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = ItemCategory::findOrFail($id);

        $validated = $request->validate([
            'parent_id' => ['sometimes', 'nullable', 'uuid', 'exists:item_categories,id', "not_in:{$id}"],
            'name' => ['sometimes', 'string', 'max:255'],
            'code_segment' => ['sometimes', 'required', 'string', 'max:50'],
            // Only reachable for a category that isn't using the segment
            // system (its code_segment is null) — otherwise code is derived
            // and this field is ignored, same as on create.
            'code' => ['sometimes', 'string', 'max:50', "unique:item_categories,code,{$id}"],
            'item_type' => ['nullable', 'string', 'in:raw_material,foam_block,cut_template_piece,slice,byproduct_fill,furniture_finished_good,packaging,barrel,pallet'],
            'child_code_length' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10'],
            'description' => ['nullable', 'string'],
        ]);

        $usesSegment = array_key_exists('code_segment', $validated) || $category->code_segment !== null;

        if ($usesSegment) {
            $nextParentId = array_key_exists('parent_id', $validated) ? $validated['parent_id'] : $category->parent_id;
            $nextSegment = $validated['code_segment'] ?? $category->code_segment;

            $this->assertCodeAvailable(
                ItemCategory::buildCode($nextParentId, $nextSegment),
                excludeId: $id,
            );
        }

        $category->update($validated);

        return response()->json($category);
    }

    /** @throws ValidationException when another category already owns the computed code. */
    private function assertCodeAvailable(string $code, ?string $excludeId = null): void
    {
        $taken = ItemCategory::withoutGlobalScopes()
            ->where('code', $code)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code_segment' => ["The resulting code '{$code}' is already used by another category."],
            ]);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        $category = ItemCategory::findOrFail($id);

        if ($category->children()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a category that still has sub-categories.',
                'code' => 'CATEGORY_HAS_CHILDREN',
            ], 422);
        }

        if ($category->items()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a category that still has inventory items assigned to it.',
                'code' => 'CATEGORY_HAS_ITEMS',
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Item category deleted successfully.']);
    }
}
