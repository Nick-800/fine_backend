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
     * Display a listing of unit blueprints.
     */
    public function index(): AnonymousResourceCollection
    {
        return UnitBlueprintResource::collection(UnitBlueprint::all());
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
}
