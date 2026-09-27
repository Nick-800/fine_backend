<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CashAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operating_unit_id' => $this->operating_unit_id,
            'name' => $this->name,
            'kind' => $this->kind,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'account_code' => $this->account->account_code,
                'name' => $this->account->name,
            ] : null),
            'currency' => $this->currency,
            'balance' => (float) $this->balance,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
