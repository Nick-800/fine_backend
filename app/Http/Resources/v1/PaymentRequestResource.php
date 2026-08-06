<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operating_unit_id' => $this->operating_unit_id,
            'import_order_id' => $this->import_order_id,
            'route' => $this->route->value ?? $this->route,
            'invoice_ref' => $this->invoice_ref,
            'amount_requested' => (float) $this->amount_requested,
            'status' => $this->status->value ?? $this->status,
            'fx_rate_used' => $this->fx_rate_used ? (float) $this->fx_rate_used : null,
            'bank_hold' => new BankHoldResource($this->whenLoaded('bankHold')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
