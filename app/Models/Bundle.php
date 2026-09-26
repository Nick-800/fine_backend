<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\OperatingUnitOrSharedScope;
use App\Models\Traits\Auditable;
use App\Support\CurrentUnitContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Bundle extends Model
{
    use Auditable, HasFactory, HasUuids, SoftDeletes;

    /**
     * `operating_unit_id` is nullable here: a null bundle is shared across
     * every unit, so it must stay visible to unit-scoped users — same
     * pattern as ItemCategory.
     */
    protected static function booted(): void
    {
        self::addGlobalScope(new OperatingUnitOrSharedScope);

        self::creating(function (Bundle $bundle): void {
            if ($bundle->operating_unit_id === null) {
                $context = app(CurrentUnitContext::class);
                if ($context->hasUnit()) {
                    $bundle->operating_unit_id = $context->id();
                }
            }
        });
    }

    protected $fillable = [
        'operating_unit_id',
        'name',
        'description',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BundleItem::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }
}
