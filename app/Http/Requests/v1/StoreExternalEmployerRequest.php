<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use App\Enums\EntityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreExternalEmployerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['nullable', 'uuid', 'exists:entities,id', 'unique:external_employers,entity_id'],
            'name' => ['nullable', 'string', 'max:255'],
            'entity_type' => ['nullable', Rule::enum(EntityType::class)],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'operating_unit_id' => ['nullable', 'uuid', 'exists:operating_units,id'],
            'contract_reference' => ['nullable', 'string', 'max:100'],
            'billing_rate_multiplier' => ['nullable', 'numeric', 'min:0.01', 'max:999.99'],
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id'],
        ];
    }
}
