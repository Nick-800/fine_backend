<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\UnitBlueprintResource;
use App\Models\UnitBlueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class UnitBlueprintController extends Controller
{
    /**
     * Display a listing of unit blueprints. Pass ?with_trashed=1 to include soft-deleted.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = UnitBlueprint::query();
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        return UnitBlueprintResource::collection($query->orderBy('name')->get());
    }

    /**
     * Display the specified unit blueprint. Pass ?with_trashed=1 to resolve a soft-deleted one.
     */
    public function show(Request $request, string $id): UnitBlueprintResource
    {
        $query = UnitBlueprint::query();
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }
        $blueprint = $query->findOrFail($id);

        return new UnitBlueprintResource($blueprint);
    }

    /**
     * Create a new unit blueprint.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'workflow_set' => 'required|array',
            'default_role_template' => 'required|array',
            'default_inventory_config' => 'required|array',
        ]);

        $blueprint = UnitBlueprint::create($request->only('name', 'workflow_set', 'default_role_template', 'default_inventory_config'));

        return (new UnitBlueprintResource($blueprint))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update the specified unit blueprint.
     */
    public function update(Request $request, string $id): UnitBlueprintResource
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'workflow_set' => 'sometimes|required|array',
            'default_role_template' => 'sometimes|required|array',
            'default_inventory_config' => 'sometimes|required|array',
        ]);

        $blueprint = UnitBlueprint::findOrFail($id);
        $blueprint->update($request->only('name', 'workflow_set', 'default_role_template', 'default_inventory_config'));

        return new UnitBlueprintResource($blueprint);
    }

    /**
     * Soft-delete the specified unit blueprint.
     *
     * Refuses to delete a blueprint that still has operating units provisioned
     * against it — the foreign-key constraint on operating_units.blueprint_id
     * would otherwise leave those rows orphaned. Soft-deleting is reversible
     * via the restore endpoint.
     */
    public function destroy(string $id): JsonResponse
    {
        $blueprint = UnitBlueprint::findOrFail($id);

        if ($blueprint->operatingUnits()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف قالب لا يزال مرتبطاً بوحدات تشغيلية. احذف الوحدات أولاً.',
                'code' => 'BLUEPRINT_HAS_UNITS',
            ], 422);
        }

        $blueprint->delete();

        return response()->json([
            'message' => 'Unit blueprint deleted successfully.',
        ]);
    }

    /**
     * Restore a soft-deleted unit blueprint.
     */
    public function restore(string $id): UnitBlueprintResource
    {
        $blueprint = UnitBlueprint::withTrashed()->findOrFail($id);
        $blueprint->restore();

        return new UnitBlueprintResource($blueprint);
    }
}
