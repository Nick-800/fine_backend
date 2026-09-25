<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MaterialRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fulfilling_module' => $this->fulfilling_module,
            'inventory_item_id' => $this->inventory_item_id,
            'inventory_item' => $this->whenLoaded('inventoryItem', function () {
                return [
                    'id' => $this->inventoryItem->id,
                    'name' => $this->inventoryItem->name,
                    'sku' => $this->inventoryItem->code,
                    'item_type' => $this->inventoryItem->item_type,
                    'unit_of_measure' => $this->inventoryItem->unit_of_measure,
                ];
            }),
            'quantity' => (float) $this->quantity,
            'target_dimensions' => $this->target_dimensions,
            'status' => $this->status,
            'parent_request_id' => $this->parent_request_id,
            'requested_for_type' => $this->requested_for_type,
            'requested_for_id' => $this->requested_for_id,
            'fulfilled_by_type' => $this->fulfilled_by_type,
            'fulfilled_by_id' => $this->fulfilled_by_id,
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
            'operating_unit_id' => $this->operating_unit_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
