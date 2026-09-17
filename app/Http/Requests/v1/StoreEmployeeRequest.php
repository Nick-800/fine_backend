<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use App\Enums\EmployeeStatus;
use App\Enums\EntityType;
use App\Enums\PayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['nullable', 'uuid', 'exists:entities,id', 'unique:employees,entity_id'],
            'name' => ['nullable', 'string', 'max:255'],
            'entity_type' => ['nullable', Rule::enum(EntityType::class)],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'operating_unit_id' => ['required', 'uuid', 'exists:operating_units,id'],
            'job_title' => ['required', 'string', 'max:150'],
            'labor_role' => ['nullable', 'string', 'max:100'],
            'pay_type' => ['required', Rule::enum(PayType::class)],
            'monthly_salary' => ['nullable', 'numeric', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'hire_date' => ['required', 'date'],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
        ];
    }
}
