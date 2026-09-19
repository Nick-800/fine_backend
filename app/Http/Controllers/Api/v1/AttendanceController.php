<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date',
            'work_date' => 'sometimes|nullable|date',
            'status' => 'sometimes|nullable|string|in:present,absent,leave,half_day',
            'employee_id' => 'sometimes|nullable|uuid|exists:employees,id',
            'search' => 'sometimes|nullable|string|max:100',
        ]);

        $query = Attendance::with('employee.entity')->orderByDesc('work_date')->orderByDesc('created_at');

        if ($request->filled('work_date')) {
            $query->whereDate('work_date', $request->query('work_date'));
        }

        if ($request->filled('from')) {
            $query->whereDate('work_date', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('work_date', '<=', $request->query('to'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->query('employee_id'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->whereHas('employee.entity', function ($eq) use ($search) {
                    $eq->where('name', 'like', "%{$search}%");
                })->orWhereHas('employee', function ($eq) use ($search) {
                    $eq->where('job_title', 'like', "%{$search}%");
                })->orWhere('notes', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    /**
     * Daily grid entry: one date, many employees, upserted in one call so a
     * supervisor can correct the sheet by simply saving it again.
     */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'work_date' => 'required|date|before_or_equal:today',
            'entries' => 'required|array|min:1',
            'entries.*.employee_id' => 'required|uuid|exists:employees,id',
            'entries.*.status' => 'required|string|in:present,absent,leave,half_day',
            'entries.*.hours_worked' => 'sometimes|nullable|numeric|min:0|max:24',
            'entries.*.notes' => 'sometimes|nullable|string|max:255',
        ]);

        $saved = DB::transaction(function () use ($validated) {
            $result = [];

            foreach ($validated['entries'] as $entry) {
                $employee = Employee::withoutGlobalScopes()->findOrFail($entry['employee_id']);

                // The date cast stores a midnight timestamp, so the lookup
                // must compare by date, not by the raw string.
                $existing = Attendance::withoutGlobalScopes()
                    ->where('employee_id', $employee->id)
                    ->whereDate('work_date', $validated['work_date'])
                    ->first();

                $attributes = [
                    'operating_unit_id' => $employee->operating_unit_id,
                    'status' => $entry['status'],
                    'hours_worked' => $entry['hours_worked'] ?? 0,
                    'notes' => $entry['notes'] ?? null,
                ];

                if ($existing !== null) {
                    $existing->update($attributes);
                    $result[] = $existing;
                } else {
                    $result[] = Attendance::create([
                        'employee_id' => $employee->id,
                        'work_date' => $validated['work_date'],
                        ...$attributes,
                    ]);
                }
            }

            return $result;
        });

        return response()->json([
            'message' => 'Attendance saved.',
            'count' => count($saved),
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|uuid|exists:employees,id',
            'work_date' => 'required|date|before_or_equal:today',
            'status' => 'required|string|in:present,absent,leave,half_day',
            'hours_worked' => 'sometimes|nullable|numeric|min:0|max:24',
            'notes' => 'sometimes|nullable|string|max:255',
        ]);

        $employee = Employee::withoutGlobalScopes()->findOrFail($validated['employee_id']);

        $defaultHours = match ($validated['status']) {
            'present' => 8.0,
            'half_day' => 4.0,
            default => 0.0,
        };

        $attendance = Attendance::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $validated['work_date'])
            ->first();

        $attributes = [
            'operating_unit_id' => $employee->operating_unit_id,
            'status' => $validated['status'],
            'hours_worked' => array_key_exists('hours_worked', $validated) && $validated['hours_worked'] !== null
                ? (float) $validated['hours_worked']
                : $defaultHours,
            'notes' => $validated['notes'] ?? null,
        ];

        if ($attendance !== null) {
            $attendance->update($attributes);
        } else {
            $attendance = Attendance::create([
                'employee_id' => $employee->id,
                'work_date' => $validated['work_date'],
                ...$attributes,
            ]);
        }

        return response()->json($attendance->load('employee.entity'), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $attendance = Attendance::findOrFail($id);

        $validated = $request->validate([
            'status' => 'sometimes|required|string|in:present,absent,leave,half_day',
            'hours_worked' => 'sometimes|nullable|numeric|min:0|max:24',
            'notes' => 'sometimes|nullable|string|max:255',
        ]);

        $attendance->update($validated);

        return response()->json($attendance->refresh()->load('employee.entity'));
    }

    public function destroy(string $id): JsonResponse
    {
        $attendance = Attendance::findOrFail($id);
        $attendance->delete();

        return response()->json(['message' => 'Attendance record deleted.']);
    }
}
