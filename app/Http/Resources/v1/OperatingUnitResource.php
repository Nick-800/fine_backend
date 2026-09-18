<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OperatingUnitResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'blueprint_id' => $this->blueprint_id,
            'name' => $this->name,
            'unit_type' => $this->unit_type,
            'currency' => $this->currency,
            'status' => $this->status,
            'manager_user_id' => $this->manager_user_id,
            'manager' => $this->whenLoaded('manager', fn () => $this->manager ? [
                'id' => $this->manager->id,
                'name' => $this->manager->name,
            ] : null),
            'warehouses' => $this->whenLoaded('warehouses', fn () => $this->warehouses->map(fn ($w) => [
                'id' => $w->id,
                'operating_unit_id' => $w->operating_unit_id,
                'name' => $w->name,
                'is_internal_unit' => (bool) $w->is_internal_unit,
                'created_at' => $w->created_at?->toIso8601String(),
                'updated_at' => $w->updated_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
