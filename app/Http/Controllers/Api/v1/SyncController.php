<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveQuarantineRequest;
use App\Http\Requests\SyncPullRequest;
use App\Http\Requests\SyncPushRequest;
use App\Models\OperatingUnit;
use App\Models\SyncConflict;
use App\Services\SyncService;
use App\Support\CurrentUnitContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SyncController extends Controller
{
    public function __construct(
        private readonly SyncService $syncService,
        private readonly CurrentUnitContext $unitContext,
    ) {}

    /**
     * Pull delta updates for syncable models.
     */
    public function pull(SyncPullRequest $request): JsonResponse
    {
        $since = $request->query('since') ? (string) $request->query('since') : null;
        $user = $request->user();
        $unit = $this->unitContext->unit() ?? OperatingUnit::first();

        if (! $unit) {
            return response()->json(['message' => 'No operating unit context available.'], 400);
        }

        $data = $this->syncService->processPull($user, $unit, $since);

        return response()->json($data);
    }

    /**
     * Push batched local outbox actions to the server.
     */
    public function push(SyncPushRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $unit = $this->unitContext->unit() ?? OperatingUnit::first();

        if (! $unit) {
            return response()->json(['message' => 'No operating unit context available.'], 400);
        }

        $data = $this->syncService->processPush(
            $user,
            $unit,
            $validated['device_id'],
            $validated['outbox']
        );

        return response()->json($data);
    }

    /**
     * Display a listing of quarantined sync conflicts.
     */
    public function quarantinedIndex(Request $request): JsonResponse
    {
        $unit = $this->unitContext->unit();

        $query = SyncConflict::where('operating_unit_id', $unit->id)
            ->with('resolver')
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    /**
     * Resolve a quarantined sync conflict (override, edit, void).
     */
    public function resolveQuarantine(ResolveQuarantineRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        /** @var SyncConflict $conflict */
        $conflict = SyncConflict::findOrFail($id);

        $resolved = $this->syncService->resolveQuarantine(
            $conflict,
            $user,
            $validated['action'],
            $validated['modified_data'] ?? null
        );

        return response()->json([
            'message' => 'Sync conflict resolved successfully.',
            'data' => $resolved,
        ]);
    }
}
