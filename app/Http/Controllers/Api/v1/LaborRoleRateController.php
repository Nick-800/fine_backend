<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\LaborRoleRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LaborRoleRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LaborRoleRate::orderBy('role')->orderByDesc('effective_from');

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * HR-04: rates are append-only versions. Correcting a mistake means
     * publishing a new version, not rewriting history that snapshots may
     * already have been taken from.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'required|string|max:100',
            'hourly_rate' => 'required|numeric|min:0.0001',
            'effective_from' => 'required|date',
        ]);

        // The date cast stores midnight timestamps, so uniqueness has to be
        // checked by date rather than by the raw column value.
        $duplicate = LaborRoleRate::where('role', $validated['role'])
            ->whereDate('effective_from', $validated['effective_from'])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'A rate version for this role and effective date already exists; publish a new date instead.',
                'code' => 'DUPLICATE_RATE_VERSION',
            ], 422);
        }

        $rate = LaborRoleRate::create($validated);

        return response()->json($rate, 201);
    }

    /**
     * The rate in force today for every role that has one.
     */
    public function current(): JsonResponse
    {
        $current = LaborRoleRate::whereDate('effective_from', '<=', now())
            ->orderBy('effective_from')
            ->get()
            ->groupBy('role')
            ->map(fn ($versions) => $versions->last())
            ->values();

        return response()->json(['data' => $current]);
    }
}
