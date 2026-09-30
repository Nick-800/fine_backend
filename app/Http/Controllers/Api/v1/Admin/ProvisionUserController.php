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
            'email' => 'nullable|email|unique:users,email',
            'phone' => ['nullable', 'regex:/^09[0-9]{8}$/', 'unique:users,phone'],
            'password' => 'nullable|string|min:8',
        ], [
            'phone.regex' => 'رقم الهاتف يجب أن يتكون من 10 أرقام ويبدأ بـ 09.',
        ]);

        $email = $request->input('email') ?: $entity->primaryContact?->email;
        $rawPhone = $request->input('phone') ?: $entity->primaryContact?->phone;
        $phone = ($rawPhone && preg_match('/^09[0-9]{8}$/', $rawPhone)) ? $rawPhone : null;

        if (empty($email) && empty($phone)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => ['يجب توفير البريد الإلكتروني أو رقم هاتف صالح (10 أرقام يبدأ بـ 09).'],
            ]);
        }

        $plainPassword = $request->input('password') ?: Str::random(16);

        DB::transaction(function () use ($entity, $email, $phone, $plainPassword): void {
            $user = User::create([
                'name' => $entity->name,
                'email' => $email,
                'phone' => $phone,
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
