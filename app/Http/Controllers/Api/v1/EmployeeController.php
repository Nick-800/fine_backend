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
use App\Models\Scopes\OperatingUnitScope;
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
        $query = Employee::with(['entity', 'operatingUnit']);

        // Unit drill-down is only honoured for company-wide roles; a unit
        // caller's listing stays inside their ambient unit scope. Lifting only
        // the unit scope keeps SoftDeletes intact.
        if ($request->has('operating_unit_id') && $request->user()->hasCompanyWideRole()) {
            $query->withoutGlobalScope(OperatingUnitScope::class)
                ->where('operating_unit_id', $request->query('operating_unit_id'));
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
                'job_title' => $request->job_title,
                'labor_role' => $request->labor_role,
                'pay_type' => $request->pay_type,
                'monthly_salary' => $request->monthly_salary,
                'hourly_rate' => $request->hourly_rate,
                'hire_date' => $request->hire_date,
                'status' => $request->input('status', 'active'),
            ]);

            return $employee->load(['entity', 'operatingUnit']);
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
        $employee = Employee::with(['entity', 'operatingUnit'])->findOrFail($id);

        return new EmployeeResource($employee);
    }

    /**
     * Update the specified employee.
     */
    public function attendance(Request $request, string $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        return response()->json(
            $employee->attendances()->orderByDesc('work_date')->paginate($request->integer('per_page', 31))
        );
    }

    public function payslips(Request $request, string $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        return response()->json(
            $employee->payslips()->with('payrollRun:id,period,status')
                ->latest()->paginate($request->integer('per_page', 24))
        );
    }

    public function laborLogs(Request $request, string $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        return response()->json(
            $employee->load(['laborLogs' => fn ($q) => $q->with('productionOrder:id,order_number')->latest('logged_at')])
                ->laborLogs
                ->paginate($request->integer('per_page', 24))
        );
    }

    public function update(Request $request, string $id): EmployeeResource
    {
        $employee = Employee::findOrFail($id);

        $request->validate([
            'operating_unit_id' => 'sometimes|required|uuid|exists:operating_units,id',
            'job_title' => 'sometimes|required|string|max:150',
            'labor_role' => 'sometimes|nullable|string|max:100',
            'pay_type' => 'sometimes|required|string',
            'monthly_salary' => 'sometimes|nullable|numeric|min:0',
            'hourly_rate' => 'sometimes|nullable|numeric|min:0',
            'hire_date' => 'sometimes|required|date',
            'status' => 'sometimes|required|string',
            'record_version' => 'required|integer',
        ]);

        $employee->update($request->only(
            'operating_unit_id',
            'job_title',
            'labor_role',
            'pay_type',
            'monthly_salary',
            'hourly_rate',
            'hire_date',
            'status',
            'record_version'
        ));

        return new EmployeeResource($employee->load(['entity', 'operatingUnit']));
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
