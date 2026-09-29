<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToOperatingUnit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received against a sale — at checkout, or later against a
 * receivable. Written only by SaleCheckoutService, always with its journal.
 */
final class SalePayment extends Model
{
    use BelongsToOperatingUnit, HasFactory, HasUuids;

    protected $fillable = [
        'operating_unit_id',
        'sales_order_id',
        'client_id',
        'amount',
        'method',
        'cash_account_id',
        'received_at',
        'received_by_user_id',
        'journal_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'received_at' => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
