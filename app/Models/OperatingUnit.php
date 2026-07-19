<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OperatingUnit extends Model
{
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'company_id',
        'blueprint_id',
        'name',
        'unit_type',
        'currency',
        'status',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(UnitBlueprint::class, 'blueprint_id');
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }
}
