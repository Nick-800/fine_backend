<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\v1\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AuditLogController extends Controller
{
    /**
     * Display a listing of audit logs.
     */
    public function index(): AnonymousResourceCollection
    {
        return AuditLogResource::collection(AuditLog::latest()->get());
    }

    /**
     * Display audit logs associated with a specific database record.
     */
    public function show(string $tableName, string $recordId): AnonymousResourceCollection
    {
        $logs = AuditLog::where('table_name', $tableName)
            ->where('record_id', $recordId)
            ->latest()
            ->get();

        return AuditLogResource::collection($logs);
    }
}
