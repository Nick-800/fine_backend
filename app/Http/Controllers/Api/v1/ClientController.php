<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreClientRequest;
use App\Http\Resources\v1\ClientResource;
use App\Models\Client;
use App\Models\Entity;
use App\Services\EntityService;
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
    public function store(StoreClientRequest $request, EntityService $entityService): JsonResponse
    {
        $client = DB::transaction(function () use ($request, $entityService) {
            $entityId = $request->entity_id;

            if (! $entityId) {
                $name = $request->input('name', 'Client Company');

                $entity = $entityService->createEntityForDomainModel(
                    roleType: EntityRoleType::Client,
                    attributes: [
                        'name' => $name,
                        'entity_type' => $request->input('entity_type', EntityType::Organization),
                        'tax_number' => $request->tax_number,
                    ],
                    operatingUnitId: $request->operating_unit_id
                );
                $entityId = $entity->id;
            } else {
                $entity = Entity::findOrFail($entityId);
                $entityService->ensureEntityRole($entity, EntityRoleType::Client, $request->operating_unit_id);
            }

            $client = Client::create([
                'entity_id' => $entityId,
                'operating_unit_id' => $request->operating_unit_id,
                'credit_limit' => $request->input('credit_limit', 0),
                'payment_terms_days' => $request->input('payment_terms_days', 30),
                'account_id' => $request->account_id,
                'status' => $request->input('status', 'active'),
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

    /**
     * Split this client into a separate standalone Entity.
     */
    public function splitEntity(Request $request, string $id, EntityService $entityService): JsonResponse
    {
        $client = Client::with('entity')->findOrFail($id);

        $request->validate([
            'new_name' => 'nullable|string|max:255',
        ]);

        $entityService->splitEntity(
            $client,
            EntityRoleType::Client,
            $request->input('new_name')
        );

        return (new ClientResource($client->load(['entity', 'operatingUnit'])))
            ->response();
    }

    /**
     * Re-link this client to a different Entity.
     */
    public function relinkEntity(Request $request, string $id, EntityService $entityService): JsonResponse
    {
        $client = Client::findOrFail($id);

        $request->validate([
            'target_entity_id' => 'required|uuid|exists:entities,id',
        ]);

        $entityService->relinkEntity(
            $client,
            $request->target_entity_id,
            EntityRoleType::Client,
            $client->operating_unit_id
        );

        return (new ClientResource($client->load(['entity', 'operatingUnit'])))
            ->response();
    }
}
