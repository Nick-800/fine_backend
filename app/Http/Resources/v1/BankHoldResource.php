<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BankHoldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_request_id' => $this->payment_request_id,
            'held_amount_lyd' => (float) $this->held_amount_lyd,
            'exact_amount_used' => (float) $this->exact_amount_used,
            'released_amount' => (float) $this->released_amount,
            'bank_reference' => $this->bank_reference,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
