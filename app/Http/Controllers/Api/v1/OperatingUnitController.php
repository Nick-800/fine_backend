<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\OperatingUnitResource;
use App\Models\OperatingUnit;
use App\Models\UnitBlueprint;
use App\Services\OperatingUnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OperatingUnitController extends Controller
{
    public function __construct(
        private readonly OperatingUnitService $service
    ) {}

    /**
     * Display a listing of operating units.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        if ($user) {
            $hasCompanyWideRole = $user->roles()
                ->whereNull('user_roles.operating_unit_id')
                ->exists();

            if (! $hasCompanyWideRole) {
                $assignedUnitIds = $user->roles()
                    ->whereNotNull('user_roles.operating_unit_id')
                    ->pluck('user_roles.operating_unit_id')
                    ->unique();

                return OperatingUnitResource::collection(
                    OperatingUnit::whereIn('id', $assignedUnitIds)->get()
                );
            }
        }

        return OperatingUnitResource::collection(OperatingUnit::all());
    }

    /**
     * Provision a new operating unit.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'blueprint_id' => 'required|uuid|exists:unit_blueprints,id',
            'name' => 'required|string|max:255',
        ]);

        $blueprint = UnitBlueprint::findOrFail($request->blueprint_id);
        $unit = $this->service->provision($blueprint, $request->name);

        return (new OperatingUnitResource($unit))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified operating unit.
     */
    public function show(string $id): OperatingUnitResource
    {
        $unit = OperatingUnit::findOrFail($id);

        return new OperatingUnitResource($unit);
    }

    /**
     * Update the specified operating unit.
     */
    public function update(Request $request, string $id): OperatingUnitResource
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'status' => 'sometimes|required|string|in:provisioning,active,inactive',
            'manager_user_id' => 'sometimes|nullable|uuid|exists:users,id',
        ]);

        $unit = OperatingUnit::findOrFail($id);
        $unit->update($request->only('name', 'status', 'manager_user_id'));

        return new OperatingUnitResource($unit);
    }

    /**
     * Remove the specified operating unit from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $unit = OperatingUnit::findOrFail($id);
        $unit->delete();

        return response()->json([
            'message' => 'Operating unit deleted successfully.',
        ]);
    }
}
