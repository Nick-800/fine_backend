<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\RoleResource;
use App\Http\Resources\v1\UserResource;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;

final class UserController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::with('roles')->get());
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_active' => true,
        ]);

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified user.
     */
    public function show(string $id): UserResource
    {
        $user = User::with('roles')->findOrFail($id);

        return new UserResource($user);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, string $id): UserResource
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,'.$id,
            'password' => 'sometimes|required|string|min:8',
            'is_active' => 'sometimes|required|boolean',
            'record_version' => 'required|integer',
        ]);

        $user = User::findOrFail($id);

        $data = $request->only('name', 'email', 'is_active', 'record_version');
        if ($request->has('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return new UserResource($user);
    }

    /**
     * Remove the specified user (soft delete).
     */
    public function destroy(string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Get roles assigned to a user.
     */
    public function roles(string $id): AnonymousResourceCollection
    {
        $user = User::findOrFail($id);

        return RoleResource::collection($user->roles);
    }

    /**
     * Assign a role to a user, scoped optionally to an Operating Unit.
     */
    public function assignRole(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'role_id' => 'required|uuid|exists:roles,id',
            'operating_unit_id' => 'nullable|uuid|exists:operating_units,id',
        ]);

        $user = User::findOrFail($id);

        $exists = UserRole::where('user_id', $id)
            ->where('role_id', $request->role_id)
            ->where('operating_unit_id', $request->operating_unit_id)
            ->exists();

        if (! $exists) {
            UserRole::create([
                'user_id' => $id,
                'role_id' => $request->role_id,
                'operating_unit_id' => $request->operating_unit_id,
            ]);
        }

        return response()->json([
            'message' => 'Role assigned successfully.',
        ]);
    }

    /**
     * Remove a role assignment.
     */
    public function removeRole(Request $request, string $id, string $roleId): JsonResponse
    {
        $request->validate([
            'operating_unit_id' => 'nullable|uuid|exists:operating_units,id',
        ]);

        UserRole::where('user_id', $id)
            ->where('role_id', $roleId)
            ->where('operating_unit_id', $request->operating_unit_id)
            ->delete();

        return response()->json([
            'message' => 'Role removed successfully.',
        ]);
    }
}
