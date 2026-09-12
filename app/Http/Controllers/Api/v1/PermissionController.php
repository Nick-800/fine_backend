<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PermissionController extends Controller
{
    /**
     * Display the catalog of permissions, grouped for the role editor.
     */
    public function index(): AnonymousResourceCollection
    {
        return PermissionResource::collection(
            Permission::orderBy('module')->orderBy('action')->get(),
        );
    }
}
