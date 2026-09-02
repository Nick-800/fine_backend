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
use Illuminate\Validation\ValidationException;

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

        $data = $request->validate([
            'type' => 'required|string|in:supplier_price,fx_spread,customs,freight,local_transport,other',
            'amount' => 'required|numeric|min:0',
            'currency' => 'sometimes|string|size:3',
            'is_confirmed' => 'sometimes|boolean',
            'note' => 'nullable|string|max:500',
        ]);

        if (
            $data['type'] === 'fx_spread'
            && (float) $data['amount'] != 0
            && blank($data['note'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'note' => 'سبب فرق سعر الصرف مطلوب عند تسجيل قيمة غير صفرية.',
            ]);
        }

        $line = $order->landedCostLines()->create([
            'type' => $data['type'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'LYD',
            'is_confirmed' => $request->boolean('is_confirmed', false),
            'note' => $data['note'] ?? null,
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
