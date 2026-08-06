<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\FxRateResource;
use App\Models\FxRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FxRateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FxRateResource::collection(FxRate::latest('captured_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'from_currency' => 'required|string|size:3',
            'to_currency' => 'required|string|size:3',
            'rate' => 'required|numeric|min:0.000001',
            'captured_at' => 'sometimes|date',
        ]);

        $fxRate = FxRate::create([
            'from_currency' => strtoupper($request->input('from_currency')),
            'to_currency' => strtoupper($request->input('to_currency')),
            'rate' => $request->input('rate'),
            'captured_at' => $request->input('captured_at', now()),
        ]);

        return (new FxRateResource($fxRate))
            ->response()
            ->setStatusCode(201);
    }
}
