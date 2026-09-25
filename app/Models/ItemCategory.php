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

final class ItemCategory extends Model
{
    use Auditable, HasFactory, HasUuids, SoftDeletes;

    /**
     * `operating_unit_id` is nullable here: a null category is shared across every
     * unit, so it must stay visible to unit-scoped users.
     */
    protected static function booted(): void
    {
        self::addGlobalScope(new OperatingUnitOrSharedScope);

        self::creating(function (ItemCategory $category): void {
            if ($category->operating_unit_id === null) {
                $context = app(CurrentUnitContext::class);
                if ($context->hasUnit()) {
                    $category->operating_unit_id = $context->id();
                }
            }
        });

        // The full code is never entered directly — it's always the parent's
        // code plus this category's own segment, recomputed on every save.
        self::saving(function (ItemCategory $category): void {
            $category->code = self::buildCode($category->parent_id, $category->code_segment);
        });

        // Re-parenting or re-segmenting a category invalidates every
        // descendant's code, so cascade the recompute down the tree.
        self::saved(function (ItemCategory $category): void {
            if ($category->wasChanged('code')) {
                $category->children()->get()->each(fn (self $child) => $child->save());
            }
        });
    }

    /** The parent's code (or '' at the root) plus this category's own segment. */
    public static function buildCode(?string $parentId, ?string $codeSegment): string
    {
        $parentCode = $parentId
            ? (self::withoutGlobalScopes()->find($parentId)?->code ?? '')
            : '';

        return $parentCode.(string) $codeSegment;
    }

    protected $fillable = [
        'operating_unit_id',
        'parent_id',
        'name',
        'code_segment',
        'code',
        'item_type',
        'child_code_length',
        'description',
    ];

    protected $casts = [
        'child_code_length' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'category_id');
    }
}
