<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\CashAccountResource;
use App\Models\CashAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CashAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CashAccount::query();

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        return CashAccountResource::collection($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'operating_unit_id' => 'required|uuid|exists:operating_units,id',
            'name' => 'required|string|max:255',
            'currency' => 'sometimes|string|size:3',
            'balance' => 'sometimes|numeric',
        ]);

        $account = CashAccount::create([
            'operating_unit_id' => $request->input('operating_unit_id'),
            'name' => $request->input('name'),
            'currency' => strtoupper($request->input('currency', 'LYD')),
            'balance' => $request->input('balance', 0),
        ]);

        return (new CashAccountResource($account))
            ->response()
            ->setStatusCode(201);
    }
}
