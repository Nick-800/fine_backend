<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\EntityRoleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreExternalEmployerRequest;
use App\Http\Resources\v1\ExternalEmployerResource;
use App\Models\EntityRole;
use App\Models\ExternalEmployer;
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
    public function store(StoreExternalEmployerRequest $request): JsonResponse
    {
        $externalEmployer = DB::transaction(function () use ($request) {
            $externalEmployer = ExternalEmployer::create([
                'entity_id' => $request->entity_id,
                'contract_reference' => $request->contract_reference,
                'billing_rate_multiplier' => $request->input('billing_rate_multiplier', 1.00),
                'account_id' => $request->account_id,
            ]);

            EntityRole::firstOrCreate([
                'entity_id' => $request->entity_id,
                'role_type' => EntityRoleType::ExternalEmployer,
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
}
