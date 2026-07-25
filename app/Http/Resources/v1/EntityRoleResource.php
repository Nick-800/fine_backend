<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EntityRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role_type' => $this->role_type->value ?? $this->role_type,
            'operating_unit_id' => $this->operating_unit_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
