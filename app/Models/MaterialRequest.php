<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MaterialRequest extends Model
{
    use Auditable, HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    public const MODULE_CUTTER = 'cutter';

    public const MODULE_FOAM = 'foam';

    public const MODULE_PROCUREMENT = 'procurement';

    protected $fillable = [
        'fulfilling_module',
        'inventory_item_id',
        'quantity',
        'target_dimensions',
        'status',
        'parent_request_id',
        'requested_for_type',
        'requested_for_id',
        'fulfilled_by_type',
        'fulfilled_by_id',
        'fulfilled_at',
        'operating_unit_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'target_dimensions' => 'array',
        'fulfilled_at' => 'datetime',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_request_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS], true);
    }
}
