<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SystemVersionController extends Controller
{
    /**
     * Record the client desktop version and return current backend version.
     */
    public function record(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'desktop_version' => 'required|string|max:50',
            'platform' => 'nullable|string|max:50',
        ]);

        $backendVersion = (string) config('app.version', '1.0.0');
        $user = $request->user('sanctum');
        $operatingUnitId = $request->header('X-Operating-Unit-ID');

        $record = AppVersion::create([
            'desktop_version' => $validated['desktop_version'],
            'backend_version' => $backendVersion,
            'user_id' => $user?->id,
            'operating_unit_id' => $operatingUnitId,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'platform' => $validated['platform'] ?? null,
        ]);

        return response()->json([
            'id' => $record->id,
            'desktop_version' => $record->desktop_version,
            'backend_version' => $record->backend_version,
            'platform' => $record->platform,
            'recorded_at' => $record->created_at?->toIso8601String(),
        ], 201);
    }

    /**
     * Get the current backend version without recording a new log entry.
     */
    public function current(): JsonResponse
    {
        return response()->json([
            'backend_version' => (string) config('app.version', '1.0.0'),
        ]);
    }

    /**
     * List recorded versions (Owner / Admin oversight).
     */
    public function index(Request $request): JsonResponse
    {
        $versions = AppVersion::with([
            'user:id,name,email',
            'operatingUnit:id,name',
        ])
            ->latest()
            ->paginate((int) $request->input('per_page', 50));

        return response()->json($versions);
    }
}
