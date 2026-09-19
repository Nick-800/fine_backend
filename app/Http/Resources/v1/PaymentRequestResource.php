<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $bookedRate = (float) ($this->importOrder?->booked_fx_rate ?? 0);
        $usedRate = $this->fx_rate_used !== null ? (float) $this->fx_rate_used : null;
        $amount = (float) $this->amount_requested;
        $extraAllocationLyd = $usedRate !== null ? round(($usedRate - $bookedRate) * $amount, 4) : null;

        return [
            'id' => $this->id,
            'operating_unit_id' => $this->operating_unit_id,
            'import_order_id' => $this->import_order_id,
            'route' => $this->route->value ?? $this->route,
            'invoice_ref' => $this->invoice_ref,
            'amount_requested' => $amount,
            'status' => $this->status->value ?? $this->status,
            'fx_rate_used' => $usedRate,
            'extra_allocation_note' => $this->extra_allocation_note,
            'extra_allocation_lyd' => $extraAllocationLyd,
            'booked_fx_rate' => $bookedRate > 0 ? $bookedRate : null,
            'bank_hold' => new BankHoldResource($this->whenLoaded('bankHold')),
            'import_order' => $this->whenLoaded('importOrder', function () {
                return [
                    'id' => $this->importOrder->id,
                    'order_number' => $this->importOrder->order_number,
                    'status' => $this->importOrder->status?->value ?? $this->importOrder->status,
                    'supplier_id' => $this->importOrder->supplier_id,
                    'supplier' => $this->importOrder->supplier ? [
                        'id' => $this->importOrder->supplier->id,
                        'name' => $this->importOrder->supplier->name,
                        'code' => $this->importOrder->supplier->code ?? null,
                        'contact_person' => $this->importOrder->supplier->contact_person ?? null,
                        'phone' => $this->importOrder->supplier->phone ?? null,
                    ] : null,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
