<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SyncConflictStatus;
use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToOperatingUnit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SyncConflict extends Model
{
    use Auditable, BelongsToOperatingUnit, HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'operating_unit_id',
        'device_id',
        'table_name',
        'record_id',
        'action',
        'base_version',
        'server_version',
        'payload',
        'conflict_reason',
        'status',
        'resolved_by_user_id',
        'resolved_at',
    ];

    protected $casts = [
        'base_version' => 'integer',
        'server_version' => 'integer',
        'payload' => 'array',
        'status' => SyncConflictStatus::class,
        'resolved_at' => 'immutable_datetime',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
