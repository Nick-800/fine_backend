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
     * Display a listing of operating units. Pass ?with_trashed=1 to include
     * soft-deleted ones (admin-only — used by the management page's
     * 'محذوفة' tab).
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

                $query = OperatingUnit::whereIn('id', $assignedUnitIds);
            } else {
                $query = OperatingUnit::query();
            }
        } else {
            $query = OperatingUnit::query();
        }

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        return OperatingUnitResource::collection($query->orderBy('name')->get());
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
     * Display the specified operating unit. Pass ?with_trashed=1 to resolve
     * a soft-deleted unit by id.
     */
    public function show(Request $request, string $id): OperatingUnitResource
    {
        $query = OperatingUnit::query();
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }
        $unit = $query->findOrFail($id);

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
     * Soft-delete the specified operating unit.
     */
    public function destroy(string $id): JsonResponse
    {
        $unit = OperatingUnit::findOrFail($id);
        $unit->delete();

        return response()->json([
            'message' => 'Operating unit deleted successfully.',
        ]);
    }

    /**
     * Restore a soft-deleted operating unit.
     */
    public function restore(string $id): OperatingUnitResource
    {
        $unit = OperatingUnit::withTrashed()->findOrFail($id);
        $unit->restore();

        return new OperatingUnitResource($unit);
    }
}
