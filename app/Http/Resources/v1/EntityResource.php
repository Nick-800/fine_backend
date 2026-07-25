<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EntityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'entity_type' => $this->entity_type->value ?? $this->entity_type,
            'tax_number' => $this->tax_number,
            'user_id' => $this->user_id,
            'is_active' => $this->is_active,
            'record_version' => $this->record_version,
            'user' => new UserResource($this->whenLoaded('user')),
            'roles' => EntityRoleResource::collection($this->whenLoaded('roles')),
            'contacts' => EntityContactResource::collection($this->whenLoaded('contacts')),
            'primary_contact' => new EntityContactResource($this->whenLoaded('primaryContact')),
            'employee' => new EmployeeResource($this->whenLoaded('employee')),
            'client' => new ClientResource($this->whenLoaded('client')),
            'external_employer' => new ExternalEmployerResource($this->whenLoaded('externalEmployer')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
