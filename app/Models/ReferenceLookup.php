<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class ReferenceLookup extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'name',
        'code',
        'is_active',
        'notes',
        'fields',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'fields' => 'array',
        ];
    }

    /**
     * @param  Builder<ReferenceLookup>  $query
     * @return Builder<ReferenceLookup>
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * @param  Builder<ReferenceLookup>  $query
     * @return Builder<ReferenceLookup>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
