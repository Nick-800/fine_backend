<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InventoryEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (operating_unit, event_type) → links an operating unit to a
 * specific chart-of-accounts sub-account. Resolved by
 * `OperatingUnit::accountFor()` during posting — absence triggers
 * `INVENTORY_ACCOUNT_NOT_LINKED` 422.
 */
final class OperatingUnitAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'operating_unit_id',
        'event_type',
        'account_id',
    ];

    protected $casts = [
        'event_type' => InventoryEventType::class,
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
