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
            'purchase_order_id' => $this->purchase_order_id,
            'type' => $this->type->value ?? $this->type,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'is_confirmed' => (bool) $this->is_confirmed,
            'note' => $this->note,
            'status' => $this->status?->value ?? $this->status,
            'approved_by_user_id' => $this->approved_by_user_id,
            'approved_at' => $this->approved_at?->toISOString(),
            'paid_by_user_id' => $this->paid_by_user_id,
            'paid_at' => $this->paid_at?->toISOString(),
            'confirmation_note' => $this->confirmation_note,
            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null),
            'payer' => $this->whenLoaded('payer', fn () => $this->payer ? [
                'id' => $this->payer->id,
                'name' => $this->payer->name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
