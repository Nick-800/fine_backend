<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EntityRoleType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EntityRole extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'entity_id',
        'role_type',
        'operating_unit_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'role_type' => EntityRoleType::class,
            'created_at' => 'datetime',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }
}
