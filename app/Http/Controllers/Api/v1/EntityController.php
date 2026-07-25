<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreEntityRequest;
use App\Http\Resources\v1\EntityResource;
use App\Models\Entity;
use App\Models\EntityContact;
use App\Models\EntityRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class EntityController extends Controller
{
    /**
     * Display a listing of entities.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Entity::with(['user', 'roles', 'contacts', 'primaryContact', 'employee', 'client', 'externalEmployer']);

        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->query('entity_type'));
        }

        if ($request->has('role_type')) {
            $query->withRole((string) $request->query('role_type'));
        }

        return EntityResource::collection($query->latest()->get());
    }

    /**
     * Store a newly created entity.
     */
    public function store(StoreEntityRequest $request): JsonResponse
    {
        $entity = DB::transaction(function () use ($request) {
            $entity = Entity::create([
                'name' => $request->name,
                'entity_type' => $request->entity_type,
                'tax_number' => $request->tax_number,
                'is_active' => $request->boolean('is_active', true),
            ]);

            if ($request->has('contact')) {
                $contactData = $request->input('contact');
                if (! empty(array_filter($contactData))) {
                    EntityContact::create(array_merge($contactData, [
                        'entity_id' => $entity->id,
                        'is_primary' => true,
                    ]));
                }
            }

            if ($request->has('roles')) {
                foreach ($request->input('roles') as $role) {
                    EntityRole::create([
                        'entity_id' => $entity->id,
                        'role_type' => $role['role_type'],
                        'operating_unit_id' => $role['operating_unit_id'] ?? null,
                    ]);
                }
            }

            return $entity->load(['roles', 'contacts', 'primaryContact']);
        });

        return (new EntityResource($entity))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified entity.
     */
    public function show(string $id): EntityResource
    {
        $entity = Entity::with(['user', 'roles', 'contacts', 'primaryContact', 'employee', 'client', 'externalEmployer'])->findOrFail($id);

        return new EntityResource($entity);
    }

    /**
     * Update the specified entity.
     */
    public function update(Request $request, string $id): EntityResource
    {
        $entity = Entity::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'tax_number' => 'nullable|string|max:100',
            'is_active' => 'sometimes|required|boolean',
            'record_version' => 'required|integer',
        ]);

        $entity->update($request->only('name', 'tax_number', 'is_active', 'record_version'));

        return new EntityResource($entity->load(['user', 'roles', 'contacts', 'primaryContact', 'employee', 'client', 'externalEmployer']));
    }

    /**
     * Soft delete the entity.
     */
    public function destroy(string $id): JsonResponse
    {
        $entity = Entity::findOrFail($id);
        $entity->delete();

        return response()->json([
            'message' => 'Entity deleted successfully.',
        ]);
    }

    /**
     * Provision a system User account for the entity.
     */
    public function provisionUser(Request $request, string $id): JsonResponse
    {
        $entity = Entity::with('primaryContact')->findOrFail($id);

        if ($entity->user_id) {
            return response()->json([
                'message' => 'Entity already has a provisioned user account.',
                'entity' => new EntityResource($entity->load('user')),
            ], 422);
        }

        $email = $entity->primaryContact?->email ?? $request->input('email');

        if (! $email) {
            $request->validate([
                'email' => 'required|email|unique:users,email',
            ]);
            $email = $request->input('email');
        } else {
            $request->validate([
                'email' => 'nullable|email|unique:users,email',
            ]);
        }

        $user = DB::transaction(function () use ($entity, $email) {
            $user = User::create([
                'name' => $entity->name,
                'email' => $email,
                'password' => Hash::make(Str::random(16)),
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $entity->update([
                'user_id' => $user->id,
            ]);

            return $user;
        });

        return (new EntityResource($entity->load('user')))
            ->response()
            ->setStatusCode(201);
    }
}
