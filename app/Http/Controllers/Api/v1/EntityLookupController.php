<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\EntityResource;
use App\Models\Entity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class EntityLookupController extends Controller
{
    /**
     * Read-only listing of entities for the domain "select existing entity" picker.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Entity::with(['user', 'roles', 'contacts', 'primaryContact', 'employee', 'client', 'externalEmployer']);

        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->query('entity_type'));
        }

        if ($request->has('role_type')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('role_type', $request->query('role_type'));
            });
        }

        return EntityResource::collection($query->latest()->get());
    }
}
