<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ExternalEmployer extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'entity_id',
        'contract_reference',
        'billing_rate_multiplier',
        'account_id',
    ];

    protected function casts(): array
    {
        return [
            'billing_rate_multiplier' => 'decimal:2',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
