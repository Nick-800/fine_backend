<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'record_version' => $this->record_version,
            'role_slugs' => $this->roles ? $this->roles->pluck('slug')->values() : [],
            'permissions' => $this->hasRole('owner')
                ? ['*']
                : ($this->roles ? $this->roles->flatMap->permissions->pluck('slug')->unique()->values() : []),
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
