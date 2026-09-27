<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use App\Services\PurchaseOrderStateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $bookedRate = $this->purchaseOrder?->booked_fx_rate !== null
            ? (float) $this->purchaseOrder->booked_fx_rate
            : null;
        $usedRate = $this->fx_rate_used !== null ? (float) $this->fx_rate_used : null;
        $exactUsed = (float) ($this->bankHold?->exact_amount_used ?? 0);
        $amount = (float) $this->amount_requested;

        // Single source of truth: settled wins if the bank told us, else the
        // rate × amount. Rate is the realized one, derived from the LYD
        // amount when LYD was supplied. The frontend uses these for the live
        // FxPreviewCard.
        $effectiveSettled = $exactUsed > 0
            ? round($exactUsed, 4)
            : ($usedRate !== null ? round($amount * $usedRate, 4) : null);

        $effectiveRate = $usedRate !== null
            ? $usedRate
            : ($exactUsed > 0 && $amount > 0 ? round($exactUsed / $amount, 6) : null);

        $varianceLyd = ($effectiveSettled !== null && $bookedRate !== null)
            ? round($effectiveSettled - $bookedRate * $amount, 4)
            : null;

        $tolerance = PurchaseOrderStateService::fxToleranceLyd();
        $hardCapPercent = PurchaseOrderStateService::fxHardCapPercent();

        $varianceWithinTolerance = $varianceLyd === null
            || abs($varianceLyd) <= $tolerance;
        $varianceExceedsHardCap = $varianceLyd !== null
            && $effectiveSettled !== null
            && $effectiveSettled > 0
            && abs($varianceLyd) > ($hardCapPercent / 100) * $effectiveSettled;

        return [
            'id' => $this->id,
            'operating_unit_id' => $this->operating_unit_id,
            'purchase_order_id' => $this->purchase_order_id,
            'route' => $this->route->value ?? $this->route,
            'invoice_ref' => $this->invoice_ref,
            'amount_requested' => $amount,
            'status' => $this->status->value ?? $this->status,
            'fx_rate_used' => $usedRate,
            'effective_settled_lyd' => $effectiveSettled,
            'effective_rate' => $effectiveRate,
            'variance_vs_booked_lyd' => $varianceLyd,
            'variance_within_tolerance' => $varianceWithinTolerance,
            'variance_exceeds_hard_cap' => $varianceExceedsHardCap,
            'fx_tolerance_lyd' => $tolerance,
            'fx_hard_cap_percent' => $hardCapPercent,
            'extra_allocation_note' => $this->extra_allocation_note,
            'booked_fx_rate' => $bookedRate,
            'bank_hold' => new BankHoldResource($this->whenLoaded('bankHold')),
            'purchase_order' => $this->whenLoaded('purchaseOrder', function () {
                return [
                    'id' => $this->purchaseOrder->id,
                    'order_number' => $this->purchaseOrder->order_number,
                    'status' => $this->purchaseOrder->status?->value ?? $this->purchaseOrder->status,
                    'supplier_id' => $this->purchaseOrder->supplier_id,
                    'supplier' => $this->purchaseOrder->supplier ? [
                        'id' => $this->purchaseOrder->supplier->id,
                        'name' => $this->purchaseOrder->supplier->name,
                        'code' => $this->purchaseOrder->supplier->code ?? null,
                        'contact_person' => $this->purchaseOrder->supplier->contact_person ?? null,
                        'phone' => $this->purchaseOrder->supplier->phone ?? null,
                    ] : null,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
