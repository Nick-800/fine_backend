<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class ProvisionUserController extends Controller
{
    /**
     * Provision a system User account for the given entity. Owner-only.
     */
    public function __invoke(Request $request, string $id): JsonResponse
    {
        if (! $request->user() || ! $request->user()->hasRole('owner')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $entity = Entity::with('primaryContact')->findOrFail($id);

        if ($entity->user_id) {
            return response()->json([
                'message' => 'Entity already has a provisioned user account.',
                'entity' => $entity->load('user'),
            ], 422);
        }

        $request->validate([
            'email' => $entity->primaryContact?->email
                ? 'nullable|email|unique:users,email'
                : 'required|email|unique:users,email',
            'password' => 'nullable|string|min:8',
        ]);

        $email = $request->input('email') ?: $entity->primaryContact?->email;
        $plainPassword = $request->input('password') ?: Str::random(16);

        DB::transaction(function () use ($entity, $email, $plainPassword): void {
            $user = User::create([
                'name' => $entity->name,
                'email' => $email,
                'password' => Hash::make($plainPassword),
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $entity->update([
                'user_id' => $user->id,
            ]);
        });

        return response()->json([
            'message' => 'User account provisioned.',
            'entity' => $entity->fresh()->load('user'),
        ], 201);
    }
}
