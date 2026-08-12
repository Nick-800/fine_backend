<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class InternalRestockRequest extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'request_number',
        'requesting_unit_id',
        'source_unit_id',
        'status',
        'decided_by_user_id',
        'decided_at',
        'notes',
        'record_version',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'record_version' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InternalRestockRequestLine::class);
    }

    public function requestingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class, 'requesting_unit_id');
    }

    public function sourceUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class, 'source_unit_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
