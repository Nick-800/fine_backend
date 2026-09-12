<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ImportOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operating_unit_id' => $this->operating_unit_id,
            'supplier_id' => $this->supplier_id,
            'supplier' => new SupplierResource($this->whenLoaded('supplier')),
            'currency' => $this->currency,
            'negotiated_price' => (float) $this->negotiated_price,
            'quantity' => (float) $this->quantity,
            'booked_fx_rate' => $this->booked_fx_rate !== null ? (float) $this->booked_fx_rate : null,
            'status' => $this->status->value ?? $this->status,
            'record_version' => $this->record_version,
            'items' => $this->whenLoaded('items', function () {
                $this->items->loadMissing('inventoryItem');

                return [
                    'data' => ImportOrderItemResource::collection($this->items),
                    'items_total' => (float) round(
                        $this->items->sum(fn ($item) => (float) $item->quantity * (float) $item->unit_price),
                        4,
                    ),
                ];
            }),
            'payment_requests' => PaymentRequestResource::collection($this->whenLoaded('paymentRequests')),
            'landed_cost_lines' => LandedCostLineResource::collection($this->whenLoaded('landedCostLines')),
            'goods_receipt' => new GoodsReceiptResource($this->whenLoaded('goodsReceipt')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
