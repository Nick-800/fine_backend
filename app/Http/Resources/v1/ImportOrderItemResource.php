<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ImportOrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'import_order_id' => $this->import_order_id,
            'inventory_item_id' => $this->inventory_item_id,
            'inventory_item' => $this->whenLoaded('inventoryItem', function () {
                return [
                    'id' => $this->inventoryItem->id,
                    'name' => $this->inventoryItem->name,
                    'sku' => $this->inventoryItem->sku,
                    'item_type' => $this->inventoryItem->item_type,
                    'unit_of_measure' => $this->inventoryItem->unit_of_measure,
                ];
            }),
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'currency' => $this->currency,
            'line_total' => (float) round((float) $this->quantity * (float) $this->unit_price, 4),
            'record_version' => $this->record_version,
        ];
    }
}
