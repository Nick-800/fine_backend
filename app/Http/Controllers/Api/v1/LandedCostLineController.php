<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\LandedCostLineResource;
use App\Models\ImportOrder;
use App\Models\LandedCostLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class LandedCostLineController extends Controller
{
    public function index(string $orderId): AnonymousResourceCollection
    {
        $order = ImportOrder::findOrFail($orderId);

        return LandedCostLineResource::collection($order->landedCostLines);
    }

    public function store(Request $request, string $orderId): JsonResponse
    {
        $order = ImportOrder::findOrFail($orderId);

        $request->validate([
            'type' => 'required|string|in:supplier_price,fx_spread,customs,freight,local_transport,other',
            'amount' => 'required|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'is_confirmed' => 'sometimes|boolean',
        ]);

        $line = $order->landedCostLines()->create([
            'type' => $request->input('type'),
            'amount' => $request->input('amount'),
            'currency' => $request->input('currency', 'LYD'),
            'is_confirmed' => $request->boolean('is_confirmed', false),
        ]);

        return (new LandedCostLineResource($line))
            ->response()
            ->setStatusCode(201);
    }

    public function confirm(string $orderId, string $lineId): JsonResponse
    {
        $line = LandedCostLine::where('import_order_id', $orderId)->findOrFail($lineId);
        $line->update(['is_confirmed' => true]);

        return response()->json([
            'message' => 'Landed cost line confirmed.',
            'data' => new LandedCostLineResource($line),
        ]);
    }
}
