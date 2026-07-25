<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_id' => $this->entity_id,
            'operating_unit_id' => $this->operating_unit_id,
            'credit_limit' => $this->credit_limit,
            'payment_terms_days' => $this->payment_terms_days,
            'account_id' => $this->account_id,
            'status' => $this->status->value ?? $this->status,
            'record_version' => $this->record_version,
            'entity' => new EntityResource($this->whenLoaded('entity')),
            'operating_unit' => new OperatingUnitResource($this->whenLoaded('operatingUnit')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
