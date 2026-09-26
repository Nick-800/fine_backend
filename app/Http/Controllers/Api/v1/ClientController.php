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
use App\Services\CoaLinkService;
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
        $query = Client::with(['entity.primaryContact', 'operatingUnit', 'account']);

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
    public function store(StoreClientRequest $request, EntityService $entityService, CoaLinkService $coaLinkService): JsonResponse
    {
        $client = DB::transaction(function () use ($request, $entityService, $coaLinkService) {
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

            $accountId = $coaLinkService->resolveOrProvisionAccount(
                $request->only(['coa_action', 'account_id', 'new_account']),
                $request->user()?->company_id
            );

            $client = Client::create([
                'entity_id' => $entityId,
                'operating_unit_id' => $request->operating_unit_id,
                'credit_limit' => $request->input('credit_limit', 0),
                'payment_terms_days' => $request->input('payment_terms_days', 30),
                'account_id' => $accountId,
                'status' => $request->input('status', 'active'),
            ]);

            return $client->load(['entity.primaryContact', 'operatingUnit', 'account']);
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
        $client = Client::with(['entity.primaryContact', 'operatingUnit', 'account'])->findOrFail($id);

        return new ClientResource($client);
    }

    /**
     * Update the specified client.
     */
    public function update(Request $request, string $id, CoaLinkService $coaLinkService): ClientResource
    {
        $client = Client::findOrFail($id);

        $request->validate([
            'operating_unit_id' => 'sometimes|required|uuid|exists:operating_units,id',
            'credit_limit' => 'sometimes|numeric|min:0',
            'payment_terms_days' => 'sometimes|integer|min:0',
            'account_id' => 'nullable|uuid|exists:accounts,id',
            'coa_action' => 'nullable|string|in:create_new,link_existing,none',
            'new_account' => 'nullable|array',
            'new_account.parent_account_id' => 'required_if:coa_action,create_new|nullable|uuid|exists:accounts,id',
            'new_account.account_code' => 'required_if:coa_action,create_new|nullable|string|max:50|unique:accounts,account_code',
            'new_account.name' => 'required_if:coa_action,create_new|nullable|string|max:255',
            'new_account.currency' => 'nullable|string|size:3',
            'status' => 'sometimes|required|string',
            'record_version' => 'required|integer',
        ]);

        $accountId = $client->account_id;
        if ($request->has('coa_action') || $request->has('account_id')) {
            $accountId = $coaLinkService->resolveOrProvisionAccount(
                $request->only(['coa_action', 'account_id', 'new_account']),
                $request->user()?->company_id
            );
        }

        $data = $request->only(
            'operating_unit_id',
            'credit_limit',
            'payment_terms_days',
            'status',
            'record_version'
        );
        $data['account_id'] = $accountId;

        $client->update($data);

        return new ClientResource($client->load(['entity', 'operatingUnit', 'account']));
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
