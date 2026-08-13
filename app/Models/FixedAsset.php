<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DepreciationMethod;
use App\Enums\FixedAssetStatus;
use App\Models\Scopes\OperatingUnitOrSharedScope;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class FixedAsset extends Model
{
    use Auditable, HasFactory, HasOptimisticLocking, HasUuids, SoftDeletes;

    protected $fillable = [
        'company_id',
        'operating_unit_id',
        'name',
        'asset_code',
        'acquisition_cost',
        'acquisition_date',
        'depreciation_method',
        'useful_life_years',
        'salvage_value',
        'status',
        'record_version',
    ];

    protected $casts = [
        'depreciation_method' => DepreciationMethod::class,
        'status' => FixedAssetStatus::class,
        'acquisition_cost' => 'decimal:4',
        'salvage_value' => 'decimal:4',
        'accumulated_depreciation' => 'decimal:4',
        'disposal_proceeds' => 'decimal:4',
        'acquisition_date' => 'date',
        'disposed_at' => 'date',
        'useful_life_years' => 'integer',
        'record_version' => 'integer',
    ];

    /**
     * Null unit = company-level asset, visible to every unit — same
     * semantics as overhead expenses.
     */
    protected static function booted(): void
    {
        self::addGlobalScope(new OperatingUnitOrSharedScope);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class);
    }

    public function bookValue(): float
    {
        return round((float) $this->acquisition_cost - (float) $this->accumulated_depreciation, 4);
    }
}
