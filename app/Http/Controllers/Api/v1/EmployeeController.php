<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Enums\EntityRoleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\v1\StoreEmployeeRequest;
use App\Http\Resources\v1\EmployeeResource;
use App\Models\Employee;
use App\Models\EntityRole;
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
    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = DB::transaction(function () use ($request) {
            $employee = Employee::create([
                'entity_id' => $request->entity_id,
                'operating_unit_id' => $request->operating_unit_id,
                'employer_entity_id' => $request->employer_entity_id,
                'job_title' => $request->job_title,
                'pay_type' => $request->pay_type,
                'hire_date' => $request->hire_date,
                'status' => $request->input('status', 'active'),
            ]);

            EntityRole::firstOrCreate([
                'entity_id' => $request->entity_id,
                'role_type' => EntityRoleType::Employee,
                'operating_unit_id' => $request->operating_unit_id,
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
}
