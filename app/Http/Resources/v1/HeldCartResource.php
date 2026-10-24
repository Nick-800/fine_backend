<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use App\Models\HeldCart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HeldCart
 */
final class HeldCartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'client' => $this->client ? [
                'id' => $this->client->id,
                'name' => $this->client->entity?->name ?? $this->client->name ?? null,
            ] : null,
            'total' => (float) $this->total,
            'line_count' => (int) $this->line_count,
            'payload' => $this->payload,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
