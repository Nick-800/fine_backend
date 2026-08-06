<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreExternalEmployerRequest;
use App\Http\Resources\v1\ExternalEmployerResource;
use App\Models\Entity;
use App\Models\ExternalEmployer;
use App\Services\EntityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class ExternalEmployerController extends Controller
{
    /**
     * Display a listing of external employers.
     */
    public function index(): AnonymousResourceCollection
    {
        return ExternalEmployerResource::collection(ExternalEmployer::with('entity')->latest()->get());
    }

    /**
     * Store a newly created external employer.
     */
    public function store(StoreExternalEmployerRequest $request, EntityService $entityService): JsonResponse
    {
        $externalEmployer = DB::transaction(function () use ($request, $entityService) {
            $entityId = $request->entity_id;

            if (! $entityId) {
                $name = $request->input('name', 'External Employer Firm');

                $entity = $entityService->createEntityForDomainModel(
                    roleType: EntityRoleType::ExternalEmployer,
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
                $entityService->ensureEntityRole($entity, EntityRoleType::ExternalEmployer, $request->operating_unit_id);
            }

            $externalEmployer = ExternalEmployer::create([
                'entity_id' => $entityId,
                'contract_reference' => $request->contract_reference,
                'billing_rate_multiplier' => $request->input('billing_rate_multiplier', 1.00),
                'account_id' => $request->account_id,
            ]);

            return $externalEmployer->load('entity');
        });

        return (new ExternalEmployerResource($externalEmployer))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified external employer.
     */
    public function show(string $id): ExternalEmployerResource
    {
        $externalEmployer = ExternalEmployer::with('entity')->findOrFail($id);

        return new ExternalEmployerResource($externalEmployer);
    }

    /**
     * Update the specified external employer.
     */
    public function update(Request $request, string $id): ExternalEmployerResource
    {
        $externalEmployer = ExternalEmployer::findOrFail($id);

        $request->validate([
            'contract_reference' => 'nullable|string|max:100',
            'billing_rate_multiplier' => 'sometimes|numeric|min:0.01|max:999.99',
            'account_id' => 'nullable|uuid|exists:accounts,id',
        ]);

        $externalEmployer->update($request->only(
            'contract_reference',
            'billing_rate_multiplier',
            'account_id'
        ));

        return new ExternalEmployerResource($externalEmployer->load('entity'));
    }

    /**
     * Split this external employer into a separate standalone Entity.
     */
    public function splitEntity(Request $request, string $id, EntityService $entityService): JsonResponse
    {
        $externalEmployer = ExternalEmployer::with('entity')->findOrFail($id);

        $request->validate([
            'new_name' => 'nullable|string|max:255',
        ]);

        $entityService->splitEntity(
            $externalEmployer,
            EntityRoleType::ExternalEmployer,
            $request->input('new_name')
        );

        return (new ExternalEmployerResource($externalEmployer->load('entity')))
            ->response();
    }

    /**
     * Re-link this external employer to a different Entity.
     */
    public function relinkEntity(Request $request, string $id, EntityService $entityService): JsonResponse
    {
        $externalEmployer = ExternalEmployer::findOrFail($id);

        $request->validate([
            'target_entity_id' => 'required|uuid|exists:entities,id',
        ]);

        $entityService->relinkEntity(
            $externalEmployer,
            $request->target_entity_id,
            EntityRoleType::ExternalEmployer
        );

        return (new ExternalEmployerResource($externalEmployer->load('entity')))
            ->response();
    }
}
