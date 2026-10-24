<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToOperatingUnit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A held / parked cart at the POS counter (الفواتير المعلقة).
 *
 * Holds no stock and posts no financial or inventory movements.
 * Checkout re-validates everything when the cart is resumed and sold.
 */
final class HeldCart extends Model
{
    use BelongsToOperatingUnit, HasUuids;

    protected $fillable = [
        'operating_unit_id',
        'user_id',
        'label',
        'client_id',
        'payload',
        'total',
        'line_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'total' => 'decimal:4',
            'line_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }
}
