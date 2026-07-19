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
     * Display a listing of the roles.
     */
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::all());
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
        ]);

        $role = Role::create($request->only('name', 'slug', 'description'));

        return (new RoleResource($role))
            ->response()
            ->setStatusCode(201);
    }
}
