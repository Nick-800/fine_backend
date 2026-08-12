<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CreditApprovalRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'sales_order_id',
        'amount_over_limit',
        'status',
        'decided_by_user_id',
        'decided_at',
        'notes',
    ];

    protected $casts = [
        'amount_over_limit' => 'decimal:4',
        'decided_at' => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
