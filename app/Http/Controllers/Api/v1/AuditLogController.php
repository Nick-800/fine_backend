<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class AuditLogController extends Controller
{
    private const VALID_ACTIONS = ['created', 'updated', 'deleted', 'restored'];

    /**
     * Display a listing of audit logs.
     */
    public function index(): AnonymousResourceCollection
    {
        return AuditLogResource::collection(AuditLog::latest()->get());
    }

    /**
     * Display audit logs associated with a specific database record, with
     * optional filters on action type and date range.
     */
    public function show(Request $request, string $tableName, string $recordId): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'action' => ['sometimes', 'string', Rule::in(self::VALID_ACTIONS)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $query = AuditLog::where('table_name', $tableName)
            ->where('record_id', $recordId)
            ->latest();

        if (isset($validated['action'])) {
            $query->where('action', $validated['action']);
        }

        if (isset($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        return AuditLogResource::collection($query->get());
    }
}
