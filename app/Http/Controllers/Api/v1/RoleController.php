<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RoleController extends Controller
{
    /**
     * Display a listing of the roles. Pass ?with_trashed=1 to include soft-deleted.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Role::query();
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        return RoleResource::collection($query->orderBy('name')->get());
    }

    /**
     * Display the specified role with its current permissions.
     */
    public function show(Request $request, string $id): RoleResource
    {
        $query = Role::query();
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }
        $role = $query->with('permissions')->findOrFail($id);

        return new RoleResource($role);
    }

    /**
     * Create a new role.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:roles,slug|max:255',
            'description' => 'nullable|string',
            'permission_ids' => 'sometimes|array',
            'permission_ids.*' => 'uuid|exists:permissions,id',
        ]);

        $role = Role::create($request->only('name', 'slug', 'description'));

        if ($request->has('permission_ids')) {
            $role->permissions()->sync($request->input('permission_ids', []));
        }

        return (new RoleResource($role->load('permissions')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update the specified role.
     *
     * Slug is intentionally not updatable — it's a system identifier.
     * Permission sync is part of the same call (single round-trip).
     */
    public function update(Request $request, string $id): RoleResource
    {
        $role = Role::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string',
            'permission_ids' => 'sometimes|array',
            'permission_ids.*' => 'uuid|exists:permissions,id',
        ]);

        $role->update($request->only('name', 'description'));

        if ($request->has('permission_ids')) {
            // Owner / Admin are wildcard roles — keep their full permission set
            // regardless of what the client sends, to avoid accidentally
            // stripping the `*` access they back.
            if (! in_array($role->slug, ['owner', 'admin'], true)) {
                $role->permissions()->sync($request->input('permission_ids', []));
            }
        }

        return new RoleResource($role->load('permissions'));
    }

    /**
     * Soft-delete the specified role.
     *
     * Refuses to delete the roles backing the require.role:owner middleware
     * (owner / admin) since doing so would lock the system out.
     * user_roles.role_id cascades on delete, so assignments drop silently.
     * A soft-deleted role can be brought back via POST /roles/{id}/restore.
     */
    public function destroy(string $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        if (in_array($role->slug, ['owner', 'admin'], true)) {
            return response()->json([
                'message' => 'The owner and admin roles are protected and cannot be deleted.',
            ], 422);
        }

        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully.',
        ]);
    }

    /**
     * Restore a soft-deleted role.
     */
    public function restore(string $id): RoleResource
    {
        $role = Role::withTrashed()->findOrFail($id);
        $role->restore();

        return new RoleResource($role->load('permissions'));
    }
}
