<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseOrderKind;
use App\Enums\PurchaseOrderStatus;
use App\Models\Traits\BelongsToOperatingUnit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

final class PurchaseOrder extends Model
{
    use BelongsToOperatingUnit, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'purchase_orders';

    protected $fillable = [
        'operating_unit_id',
        'supplier_id',
        'currency',
        'kind',
        'negotiated_price',
        'quantity',
        'booked_fx_rate',
        'arrived_warehouse_id',
        'status',
        'record_version',
    ];

    protected $casts = [
        'status' => PurchaseOrderStatus::class,
        'kind' => PurchaseOrderKind::class,
        'negotiated_price' => 'decimal:4',
        'quantity' => 'decimal:4',
        'booked_fx_rate' => 'decimal:6',
        'record_version' => 'integer',
    ];

    /**
     * Default attributes applied to every new model instance when not
     * explicitly provided on create. Mirrors the database column defaults
     * so Eloquent populates `kind` and `status` even when callers omit
     * them (most foreign-flow callers do).
     */
    protected $attributes = [
        'kind' => 'foreign',
        'status' => 'draft',
    ];

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(PaymentRequest::class);
    }

    public function landedCostLines(): HasMany
    {
        return $this->hasMany(LandedCostLine::class);
    }

    public function goodsReceipt(): HasOne
    {
        return $this->hasOne(GoodsReceipt::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function arrivedWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'arrived_warehouse_id');
    }

    public function totalCost(): float
    {
        if ($this->relationLoaded('items')) {
            if ($this->items->isNotEmpty()) {
                return (float) round(
                    $this->items->sum(fn ($item) => (float) $item->quantity * (float) $item->unit_price),
                    4
                );
            }
        } elseif ($this->items()->exists()) {
            return (float) round(
                (float) $this->items()->sum(DB::raw('quantity * unit_price')),
                4
            );
        }

        return (float) round((float) $this->negotiated_price * (float) $this->quantity, 4);
    }

    public function getTotalAmountAttribute(): float
    {
        return $this->totalCost();
    }

    /**
     * Whether every line item has been fully received.
     * Returns true for orders with no items (vacuous truth).
     */
    public function isFullyReceived(): bool
    {
        if (! $this->relationLoaded('items')) {
            $this->load('items');
        }
        if ($this->items->isEmpty()) {
            return true;
        }
        foreach ($this->items as $item) {
            if ((float) ($item->received_quantity ?? 0) < (float) $item->quantity) {
                return false;
            }
        }

        return true;
    }

    /**
     * The ledger payable account used by the receive journal: the
     * supplier-specific sub-account when one is set, otherwise the
     * generic Accounts Payable (2100).
     */
    public function payableAccountCode(): string
    {
        if ($this->supplier && $this->supplier->account_id) {
            $code = Account::where('id', $this->supplier->account_id)->value('account_code');
            if ($code) {
                return (string) $code;
            }
        }

        return '21'; // 2100 Accounts Payable (post-Wave 4 rename)
    }
}
