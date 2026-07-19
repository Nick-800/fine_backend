<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class UnitBlueprint extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'workflow_set',
        'default_role_template',
        'default_inventory_config',
    ];

    protected $casts = [
        'workflow_set' => 'array',
        'default_role_template' => 'array',
        'default_inventory_config' => 'array',
    ];

    public function operatingUnits(): HasMany
    {
        return $this->hasMany(OperatingUnit::class, 'blueprint_id');
    }
}
