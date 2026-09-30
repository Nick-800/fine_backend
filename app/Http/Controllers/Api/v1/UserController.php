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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

final class UserController extends Controller
{
    /**
     * Display a listing of the users. Pass ?with_trashed=1 to include soft-deleted.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $query = User::with('roles');
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        return UserResource::collection($query->get());
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:users,email|required_without:phone',
            'phone' => ['nullable', 'regex:/^09[0-9]{8}$/', 'unique:users,phone', 'required_without:email'],
            'password' => 'required|string|min:8',
        ], [
            'phone.regex' => 'رقم الهاتف يجب أن يتكون من 10 أرقام ويبدأ بـ 09.',
            'email.required_without' => 'يجب إدخال البريد الإلكتروني أو رقم الهاتف.',
            'phone.required_without' => 'يجب إدخال البريد الإلكتروني أو رقم الهاتف.',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'is_active' => true,
            'must_change_password' => true,
        ]);

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified user. Pass ?with_trashed=1 to resolve a soft-deleted user.
     */
    public function show(Request $request, string $id): UserResource
    {
        $query = User::query();
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }
        $user = $query->with('roles.permissions')->findOrFail($id);
        Gate::authorize('view', $user);

        return new UserResource($user);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, string $id): UserResource
    {
        $user = User::findOrFail($id);
        Gate::authorize('update', $user);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['nullable', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'regex:/^09[0-9]{8}$/', \Illuminate\Validation\Rule::unique('users', 'phone')->ignore($user->id)],
            'password' => 'sometimes|required|string|min:8',
            'is_active' => 'sometimes|required|boolean',
            'record_version' => 'required|integer',
        ], [
            'phone.regex' => 'رقم الهاتف يجب أن يتكون من 10 أرقام ويبدأ بـ 09.',
        ]);

        $resultingEmail = $request->has('email') ? $request->input('email') : $user->email;
        $resultingPhone = $request->has('phone') ? $request->input('phone') : $user->phone;

        if (empty($resultingEmail) && empty($resultingPhone)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => ['يجب توفير البريد الإلكتروني أو رقم الهاتف على الأقل.'],
            ]);
        }

        $data = $request->only('name', 'email', 'phone', 'is_active', 'record_version');
        if ($request->has('password')) {
            $data['password'] = Hash::make($request->password);
            $data['must_change_password'] = true;
        }

        $user->update($data);

        return new UserResource($user);
    }

    /**
     * Remove the specified user (soft delete).
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = User::findOrFail($id);
        Gate::authorize('delete', $user);

        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot delete your own account. Ask another owner.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Restore a soft-deleted user.
     */
    public function restore(string $id): UserResource
    {
        $user = User::withTrashed()->findOrFail($id);
        Gate::authorize('restore', $user);
        $user->restore();

        return new UserResource($user);
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
