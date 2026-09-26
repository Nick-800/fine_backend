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
use App\Models\Scopes\OperatingUnitScope;
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
        $query = Client::with(['entity.primaryContact', 'operatingUnit']);

        // Unit drill-down is only honoured for company-wide roles; a unit
        // caller's listing stays inside their ambient unit scope.
        if ($request->has('operating_unit_id') && $request->user()->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class)
                ->where('operating_unit_id', $request->query('operating_unit_id'));
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

            if ($request->filled('city') || $request->filled('address')) {
                $entity = Entity::find($entityId);
                if ($entity) {
                    $entity->contacts()->updateOrCreate(
                        ['is_primary' => true],
                        [
                            'city' => $request->input('city'),
                            'address' => $request->input('address'),
                            'country' => 'LY',
                        ]
                    );
                }
            }

            $client = Client::create([
                'entity_id' => $entityId,
                'operating_unit_id' => $request->operating_unit_id,
                'credit_limit' => $request->input('credit_limit', 0),
                'payment_terms_days' => $request->input('payment_terms_days', 30),
                'account_id' => $request->account_id,
                'status' => $request->input('status', 'active'),
            ]);

            return $client->load(['entity.primaryContact', 'operatingUnit']);
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
        $client = Client::with(['entity.primaryContact', 'operatingUnit'])->findOrFail($id);

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
