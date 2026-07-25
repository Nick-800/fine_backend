<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use App\Enums\EmployeeStatus;
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
        $entityId = $this->input('entity_id');

        return [
            'entity_id' => ['required', 'uuid', 'exists:entities,id', 'unique:employees,entity_id'],
            'operating_unit_id' => ['required', 'uuid', 'exists:operating_units,id'],
            'employer_entity_id' => [
                'nullable',
                'uuid',
                'exists:entities,id',
                Rule::notIn([$entityId]),
            ],
            'job_title' => ['required', 'string', 'max:150'],
            'pay_type' => ['required', Rule::enum(PayType::class)],
            'hire_date' => ['required', 'date'],
            'status' => ['nullable', Rule::enum(EmployeeStatus::class)],
        ];
    }
}
