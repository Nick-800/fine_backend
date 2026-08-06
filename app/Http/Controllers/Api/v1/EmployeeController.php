<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\EntityRoleType;
use App\Enums\EntityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreEmployeeRequest;
use App\Http\Resources\v1\EmployeeResource;
use App\Models\Employee;
use App\Models\Entity;
use App\Services\EntityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

final class EmployeeController extends Controller
{
    /**
     * Display a listing of employees.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Employee::with(['entity', 'operatingUnit', 'employerEntity']);

        if ($request->has('operating_unit_id')) {
            $query->where('operating_unit_id', $request->query('operating_unit_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        return EmployeeResource::collection($query->latest()->get());
    }

    /**
     * Store a newly created employee.
     */
    public function store(StoreEmployeeRequest $request, EntityService $entityService): JsonResponse
    {
        $employee = DB::transaction(function () use ($request, $entityService) {
            $entityId = $request->entity_id;

            if (! $entityId) {
                $name = $request->input('name');
                if (! $name && ($request->first_name || $request->last_name)) {
                    $name = trim(($request->first_name ?? '').' '.($request->last_name ?? ''));
                }
                if (! $name) {
                    $name = 'Employee '.$request->job_title;
                }

                $entity = $entityService->createEntityForDomainModel(
                    roleType: EntityRoleType::Employee,
                    attributes: [
                        'name' => $name,
                        'entity_type' => $request->input('entity_type', EntityType::Individual),
                        'tax_number' => $request->tax_number,
                    ],
                    operatingUnitId: $request->operating_unit_id
                );
                $entityId = $entity->id;
            } else {
                $entity = Entity::findOrFail($entityId);
                $entityService->ensureEntityRole($entity, EntityRoleType::Employee, $request->operating_unit_id);
            }

            $employee = Employee::create([
                'entity_id' => $entityId,
                'operating_unit_id' => $request->operating_unit_id,
                'employer_entity_id' => $request->employer_entity_id,
                'job_title' => $request->job_title,
                'pay_type' => $request->pay_type,
                'hire_date' => $request->hire_date,
                'status' => $request->input('status', 'active'),
            ]);

            return $employee->load(['entity', 'operatingUnit', 'employerEntity']);
        });

        return (new EmployeeResource($employee))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified employee.
     */
    public function show(string $id): EmployeeResource
    {
        $employee = Employee::with(['entity', 'operatingUnit', 'employerEntity'])->findOrFail($id);

        return new EmployeeResource($employee);
    }

    /**
     * Update the specified employee.
     */
    public function update(Request $request, string $id): EmployeeResource
    {
        $employee = Employee::findOrFail($id);

        $request->validate([
            'operating_unit_id' => 'sometimes|required|uuid|exists:operating_units,id',
            'employer_entity_id' => 'nullable|uuid|exists:entities,id|different:entity_id',
            'job_title' => 'sometimes|required|string|max:150',
            'pay_type' => 'sometimes|required|string',
            'hire_date' => 'sometimes|required|date',
            'status' => 'sometimes|required|string',
            'record_version' => 'required|integer',
        ]);

        $employee->update($request->only(
            'operating_unit_id',
            'employer_entity_id',
            'job_title',
            'pay_type',
            'hire_date',
            'status',
            'record_version'
        ));

        return new EmployeeResource($employee->load(['entity', 'operatingUnit', 'employerEntity']));
    }

    /**
     * Soft delete the specified employee.
     */
    public function destroy(string $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);
        $employee->delete();

        return response()->json([
            'message' => 'Employee deleted successfully.',
        ]);
    }

    /**
     * Split this employee into a separate standalone Entity.
     */
    public function splitEntity(Request $request, string $id, EntityService $entityService): JsonResponse
    {
        $employee = Employee::with('entity')->findOrFail($id);

        $request->validate([
            'new_name' => 'nullable|string|max:255',
        ]);

        $entityService->splitEntity(
            $employee,
            EntityRoleType::Employee,
            $request->input('new_name')
        );

        return (new EmployeeResource($employee->load(['entity', 'operatingUnit', 'employerEntity'])))
            ->response();
    }

    /**
     * Re-link this employee to a different Entity.
     */
    public function relinkEntity(Request $request, string $id, EntityService $entityService): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        $request->validate([
            'target_entity_id' => 'required|uuid|exists:entities,id',
        ]);

        $entityService->relinkEntity(
            $employee,
            $request->target_entity_id,
            EntityRoleType::Employee,
            $employee->operating_unit_id
        );

        return (new EmployeeResource($employee->load(['entity', 'operatingUnit', 'employerEntity'])))
            ->response();
    }
}
