<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\HeldCartResource;
use App\Models\HeldCart;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Held / parked carts at the POS counter (الفواتير المعلقة).
 *
 * Parked when a customer steps away; resumed later for checkout.
 * Carries no ledger entries and reserves no inventory.
 */
final class HeldCartController extends Controller
{
    public function __construct(
        private readonly CurrentUnitContext $unitContext,
    ) {}

    /**
     * List held carts for the current operating unit (newest first, capped at 50).
     */
    public function index(): JsonResponse|AnonymousResourceCollection
    {
        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Operating unit is required.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $carts = HeldCart::with(['client.entity', 'user'])
            ->latest('created_at')
            ->limit(50)
            ->get();

        return HeldCartResource::collection($carts);
    }

    /**
     * Park a new cart at this counter.
     */
    public function store(Request $request): JsonResponse
    {
        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Operating unit is required.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'uuid', 'exists:clients,id'],
            'payload' => ['required', 'array'],
            'total' => ['required', 'numeric', 'min:0'],
            'line_count' => ['required', 'integer', 'min:1'],
        ]);

        $encoded = json_encode($validated['payload']);
        if ($encoded === false || strlen($encoded) > 64 * 1024) {
            return response()->json([
                'message' => 'Cart payload exceeds the 64 KB limit.',
                'code' => 'HELD_CART_TOO_LARGE',
            ], 422);
        }

        if (HeldCart::where('operating_unit_id', $unitId)->count() >= 30) {
            return response()->json([
                'message' => 'Held cart limit reached for this operating unit (maximum 30).',
                'code' => 'HELD_CART_LIMIT',
            ], 422);
        }

        $cart = HeldCart::create([
            'operating_unit_id' => $unitId,
            'user_id' => $request->user()->id,
            'label' => $validated['label'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'payload' => $validated['payload'],
            'total' => $validated['total'],
            'line_count' => $validated['line_count'],
        ]);

        $cart->load(['client.entity', 'user']);

        return (new HeldCartResource($cart))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Discard a held cart.
     */
    public function destroy(string $id): Response|JsonResponse
    {
        $unitId = $this->unitContext->getUnitId();

        if ($unitId === null) {
            return response()->json([
                'message' => 'Operating unit is required.',
                'code' => 'OPERATING_UNIT_REQUIRED',
            ], 422);
        }

        $cart = HeldCart::findOrFail($id);
        $cart->delete();

        return response()->noContent();
    }
}
