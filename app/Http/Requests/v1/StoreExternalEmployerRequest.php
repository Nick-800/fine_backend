<?php

declare(strict_types=1);

namespace App\Http\Requests\v1;

use Illuminate\Foundation\Http\FormRequest;

final class StoreExternalEmployerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entity_id' => ['required', 'uuid', 'exists:entities,id', 'unique:external_employers,entity_id'],
            'contract_reference' => ['nullable', 'string', 'max:100'],
            'billing_rate_multiplier' => ['nullable', 'numeric', 'min:0.01', 'max:999.99'],
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id'],
        ];
    }
}
