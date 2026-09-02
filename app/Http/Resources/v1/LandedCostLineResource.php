<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LandedCostLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'import_order_id' => $this->import_order_id,
            'type' => $this->type->value ?? $this->type,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'is_confirmed' => (bool) $this->is_confirmed,
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
