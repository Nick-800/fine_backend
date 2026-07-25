<?php

declare(strict_types=1);

namespace App\Http\Resources\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_id' => $this->entity_id,
            'operating_unit_id' => $this->operating_unit_id,
            'employer_entity_id' => $this->employer_entity_id,
            'job_title' => $this->job_title,
            'pay_type' => $this->pay_type->value ?? $this->pay_type,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'status' => $this->status->value ?? $this->status,
            'record_version' => $this->record_version,
            'entity' => new EntityResource($this->whenLoaded('entity')),
            'operating_unit' => new OperatingUnitResource($this->whenLoaded('operatingUnit')),
            'employer_entity' => new EntityResource($this->whenLoaded('employerEntity')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
