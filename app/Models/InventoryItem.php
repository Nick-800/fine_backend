<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class InventoryItem extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'sku',
        'item_type',
        'unit_of_measure',
        'primary_uom',
        'secondary_uom',
        'default_attributes',
    ];

    protected $casts = [
        'default_attributes' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function attributeDefinitions(): BelongsToMany
    {
        return $this->belongsToMany(
            InventoryAttributeDefinition::class,
            'inventory_item_attribute_definitions',
            'inventory_item_id',
            'attribute_definition_id'
        );
    }
}
