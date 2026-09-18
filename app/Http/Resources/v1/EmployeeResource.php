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
            'job_title' => $this->job_title,
            'labor_role' => $this->labor_role,
            'pay_type' => $this->pay_type->value ?? $this->pay_type,
            'monthly_salary' => $this->monthly_salary !== null ? (float) $this->monthly_salary : null,
            'hourly_rate' => $this->hourly_rate !== null ? (float) $this->hourly_rate : null,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'status' => $this->status->value ?? $this->status,
            'record_version' => $this->record_version,
            'entity' => new EntityResource($this->whenLoaded('entity')),
            'operating_unit' => new OperatingUnitResource($this->whenLoaded('operatingUnit')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
