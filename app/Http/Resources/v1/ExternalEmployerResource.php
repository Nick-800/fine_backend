<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ExternalEmployerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_id' => $this->entity_id,
            'contract_reference' => $this->contract_reference,
            'billing_rate_multiplier' => $this->billing_rate_multiplier,
            'account_id' => $this->account_id,
            'entity' => new EntityResource($this->whenLoaded('entity')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
