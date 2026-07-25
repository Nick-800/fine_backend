<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\EntityRoleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreClientRequest;
use App\Http\Resources\v1\ClientResource;
use App\Models\Client;
use App\Models\EntityRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class ClientController extends Controller
{
    /**
     * Display a listing of clients.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Client::with(['entity', 'operatingUnit']);

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        return ClientResource::collection($query->latest()->get());
    }

    /**
     * Store a newly created client.
     */
    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = DB::transaction(function () use ($request) {
            $client = Client::create([
                'entity_id' => $request->entity_id,
                'operating_unit_id' => $request->operating_unit_id,
                'credit_limit' => $request->input('credit_limit', 0),
                'payment_terms_days' => $request->input('payment_terms_days', 30),
                'account_id' => $request->account_id,
                'status' => $request->input('status', 'active'),
            ]);

            EntityRole::firstOrCreate([
                'entity_id' => $request->entity_id,
                'role_type' => EntityRoleType::Client,
                'operating_unit_id' => $request->operating_unit_id,
            ]);

            return $client->load(['entity', 'operatingUnit']);
        });

        return (new ClientResource($client))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified client.
     */
    public function show(string $id): ClientResource
    {
        $client = Client::with(['entity', 'operatingUnit'])->findOrFail($id);

        return new ClientResource($client);
    }

    /**
     * Update the specified client.
     */
    public function update(Request $request, string $id): ClientResource
    {
        $client = Client::findOrFail($id);

        $request->validate([
            'operating_unit_id' => 'sometimes|required|uuid|exists:operating_units,id',
            'credit_limit' => 'sometimes|numeric|min:0',
            'payment_terms_days' => 'sometimes|integer|min:0',
            'account_id' => 'nullable|uuid|exists:accounts,id',
            'status' => 'sometimes|required|string',
            'record_version' => 'required|integer',
        ]);

        $client->update($request->only(
            'operating_unit_id',
            'credit_limit',
            'payment_terms_days',
            'account_id',
            'status',
            'record_version'
        ));

        return new ClientResource($client->load(['entity', 'operatingUnit']));
    }

    /**
     * Remove the specified client.
     */
    public function destroy(string $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $client->delete();

        return response()->json([
            'message' => 'Client deleted successfully.',
        ]);
    }
}
