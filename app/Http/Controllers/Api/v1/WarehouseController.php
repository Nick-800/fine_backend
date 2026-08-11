<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;

class WarehouseController extends Controller
{
    /**
     * Warehouses available to the current operating unit.
     *
     * Scoping is handled by the BelongsToOperatingUnit global scope on the model,
     * so no explicit unit filter is applied here.
     */
    public function index(): JsonResponse
    {
        return response()->json(Warehouse::orderBy('name')->get());
    }
}
